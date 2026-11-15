<?php

namespace App\Console\Commands;

use App\Wishlist\Catalog\WishImageStore;
use App\Wishlist\Giving\ManagerReservationPurger;
use App\Wishlist\Giving\ReservationRetention;
use Illuminate\Console\Command;

class PruneWishlist extends Command
{
    protected $signature = 'wishlist:prune';

    protected $description = 'Daily wishlist cleanup: reservations of received wishes, manager reservations, orphan image files';

    public function handle(ReservationRetention $retention, ManagerReservationPurger $purger, WishImageStore $images): int
    {
        $reservations = $retention->purgeArchived((int) config('wishlist.reservation_retention_days'));
        $purger->purgeAll();
        $files = $images->sweepOrphans(60);

        $this->info("Pruned {$reservations} reservations of received wishes and {$files} orphan image files.");

        return 0;
    }
}
