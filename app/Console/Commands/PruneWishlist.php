<?php

namespace App\Console\Commands;

use App\Profile\ProfilePhotoStore;
use App\Wishlist\Catalog\WishImageStore;
use App\Wishlist\Giving\ManagerReservationPurger;
use App\Wishlist\Giving\ReservationRetention;
use Illuminate\Console\Command;

class PruneWishlist extends Command
{
    protected $signature = 'wishlist:prune';

    protected $description = 'Daily wishlist cleanup: reservations of received wishes, manager reservations, orphan image files';

    public function handle(ReservationRetention $retention, ManagerReservationPurger $purger, WishImageStore $images, ProfilePhotoStore $photos): int
    {
        $reservations = $retention->purgeArchived((int) config('wishlist.reservation_retention_days'));
        $purger->purgeAll();
        $files = $images->sweepOrphans(60);
        $photoFiles = $photos->sweepOrphans(60);

        $this->info("Pruned {$reservations} reservations of received wishes and {$files} orphan image files and {$photoFiles} orphan profile photo files.");

        return 0;
    }
}
