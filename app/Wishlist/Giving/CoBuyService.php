<?php

namespace App\Wishlist\Giving;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use App\Wishlist\ViewerContext;
use Illuminate\Support\Facades\DB;

/**
 * Co-buy state machine: none -> open -> closed -> open. Every transition runs in a transaction that locks the
 * reservation row; an invalid transition is a 409. No log trail and no write to the wish row.
 */
final class CoBuyService
{
    public function __construct(private readonly GivingGuard $guard) {}

    public function open(User $giver, Wish $wish): void
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);

        DB::transaction(function () use ($giver, $wish) {
            $this->guard->assertStillGiver($giver, $wish->owner_id);
            $reservation = $this->lock($wish);
            abort_unless($reservation->user_id === $giver->id, 403);
            abort_unless(in_array($reservation->cobuy_state, ['none', 'closed'], true), 409);
            $reservation->forceFill(['cobuy_state' => 'open'])->save();
        });
    }

    public function close(User $giver, Wish $wish): void
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);

        DB::transaction(function () use ($giver, $wish) {
            $reservation = $this->lock($wish);
            abort_unless($reservation->user_id === $giver->id, 403);
            abort_unless($reservation->cobuy_state === 'open', 409);
            $reservation->forceFill(['cobuy_state' => 'closed'])->save();
        });
    }

    /** Join an open co-buy or change the share; the organizer may only change their own share. */
    public function contribute(User $giver, Wish $wish, ?int $amountMinor): void
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);

        DB::transaction(function () use ($giver, $wish, $amountMinor) {
            $this->guard->assertStillGiver($giver, $wish->owner_id);
            $reservation = $this->lock($wish);
            abort_unless($reservation->cobuy_state === 'open', 409);

            $row = WishContribution::query()
                ->where('reservation_id', $reservation->id)->where('user_id', $giver->id)->first();
            if ($row === null) {
                $row = new WishContribution;
                $row->forceFill(['reservation_id' => $reservation->id, 'user_id' => $giver->id]);
            }
            $row->forceFill(['amount_minor' => $amountMinor])->save();
        });
    }

    public function leave(User $giver, Wish $wish): void
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);

        DB::transaction(function () use ($giver, $wish) {
            $reservation = $this->lock($wish);
            abort_if($reservation->user_id === $giver->id, 409);
            $deleted = WishContribution::query()
                ->where('reservation_id', $reservation->id)->where('user_id', $giver->id)->delete();
            abort_if($deleted === 0, 404);
        });
    }

    private function lock(Wish $wish): WishReservation
    {
        $reservation = WishReservation::query()->where('wish_id', $wish->id)->lockForUpdate()->first();
        abort_if($reservation === null, 404);

        return $reservation;
    }
}
