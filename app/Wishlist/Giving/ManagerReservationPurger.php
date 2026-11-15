<?php

namespace App\Wishlist\Giving;

use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistManager;
use App\Models\WishReservation;

/** Removes what a person reserved or pledged on a list they now manage (managers are recipient-side). */
final class ManagerReservationPurger
{
    public function purge(int $ownerId, int $managerId): void
    {
        $wishIds = Wish::query()->where('owner_id', $ownerId)->select('id');

        // Their own reservations first (contributions cascade), then their shares in other people's reservations.
        WishReservation::query()->where('user_id', $managerId)->whereIn('wish_id', $wishIds)->delete();

        $reservationIds = WishReservation::query()->whereIn('wish_id', $wishIds)->select('id');
        WishContribution::query()->where('user_id', $managerId)->whereIn('reservation_id', $reservationIds)->delete();
    }

    public function purgeAll(): void
    {
        WishlistManager::query()->orderBy('id')->get(['owner_id', 'manager_id'])
            ->each(fn (WishlistManager $pair) => $this->purge($pair->owner_id, $pair->manager_id));
    }
}
