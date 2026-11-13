<?php

namespace App\Wishlist\Giving;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use App\Wishlist\ViewerContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reserve and cancel. Leaves no log trail and never writes to the wish row.
 */
final class ReservationService
{
    public function __construct(private readonly GivingGuard $guard) {}

    /**
     * @return User|null null on success; on a lost race the user who reserved first
     */
    public function reserve(User $giver, Wish $wish): ?User
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);
        abort_if($wish->received_at !== null, 409);

        try {
            DB::transaction(function () use ($giver, $wish) {
                $this->guard->assertStillGiver($giver, $wish->owner_id);
                $reservation = new WishReservation;
                $reservation->forceFill(['wish_id' => $wish->id, 'user_id' => $giver->id, 'cobuy_state' => 'none'])->save();
                WishContribution::query()->create(['reservation_id' => $reservation->id, 'user_id' => $giver->id, 'amount_minor' => null]);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = WishReservation::query()->where('wish_id', $wish->id)->with('user:id,name')->first();
            abort_if($existing === null, 409);

            return $existing->user;
        }

        return null;
    }

    /** Organizer only; the reservation and every contribution to it go away. */
    public function cancel(User $giver, Wish $wish): void
    {
        $this->guard->assertGiver(ViewerContext::for($giver), $wish->owner_id);

        DB::transaction(function () use ($giver, $wish) {
            $reservation = WishReservation::query()->where('wish_id', $wish->id)->lockForUpdate()->first();
            abort_if($reservation === null, 404);
            abort_unless($reservation->user_id === $giver->id, 403);
            $reservation->delete();
        });
    }
}
