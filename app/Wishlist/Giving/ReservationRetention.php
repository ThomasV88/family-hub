<?php

namespace App\Wishlist\Giving;

use App\Models\Wish;
use App\Models\WishReservation;

/** Owner paths leave reservation rows of received wishes alone; this sweep (daily prune, plan 04-11) removes them. */
final class ReservationRetention
{
    /** @return int number of reservations deleted */
    public function purgeArchived(int $days): int
    {
        $wishIds = Wish::query()->whereNotNull('received_at')->where('received_at', '<', now()->subDays($days))->select('id');

        return WishReservation::query()->whereIn('wish_id', $wishIds)->delete();
    }
}
