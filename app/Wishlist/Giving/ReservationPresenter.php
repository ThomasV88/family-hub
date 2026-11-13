<?php

namespace App\Wishlist\Giving;

use App\Models\User;
use App\Models\WishContribution;
use App\Models\WishReservation;
use App\Wishlist\Money;

/** Allowlisted `reservation` shape for the giver page. Expects user and contributions.user loaded. */
final class ReservationPresenter
{
    /** @return array<string, mixed>|null */
    public function present(?WishReservation $reservation, User $viewer): ?array
    {
        if ($reservation === null) {
            return null;
        }

        $organizerId = $reservation->user_id;
        $rows = $reservation->contributions
            ->sortBy(fn (WishContribution $c) => [$c->user_id === $organizerId ? 0 : 1, $c->created_at?->getTimestamp() ?? 0, $c->id])
            ->values();

        $mine = $rows->first(fn (WishContribution $c) => $c->user_id === $viewer->id);
        $sum = 0;
        $anyAmount = false;
        foreach ($rows as $row) {
            if ($row->amount_minor !== null) {
                $sum += $row->amount_minor;
                $anyAmount = true;
            }
        }

        return [
            'organizer' => ['id' => $organizerId, 'name' => $reservation->user->name],
            'is_mine' => $organizerId === $viewer->id,
            'cobuy_state' => $reservation->cobuy_state,
            'contributors' => $rows->map(fn (WishContribution $c) => [
                'id' => $c->user_id,
                'name' => $c->user->name,
                'amount' => Money::toDecimal($c->amount_minor),
                'is_organizer' => $c->user_id === $organizerId,
                'is_me' => $c->user_id === $viewer->id,
            ])->all(),
            'pledged' => $anyAmount ? Money::toDecimal($sum) : null,
            'people' => $rows->count(),
            'i_joined' => $mine !== null && $organizerId !== $viewer->id,
            'my_amount' => Money::toDecimal($mine?->amount_minor),
        ];
    }
}
