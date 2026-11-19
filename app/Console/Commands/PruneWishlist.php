<?php

namespace App\Console\Commands;

use App\Profile\ProfilePhotoStore;
use App\Recipes\Images\RecipeImageStore;
use App\Shopping\Images\ShoppingImageStore;
use App\Wishlist\Catalog\WishImageStore;
use App\Wishlist\Giving\GroupReservationPurger;
use App\Wishlist\Giving\ManagerReservationPurger;
use App\Wishlist\Giving\ReservationRetention;
use Illuminate\Console\Command;

class PruneWishlist extends Command
{
    protected $signature = 'wishlist:prune';

    protected $description = 'Daily wishlist cleanup: reservations of received wishes, manager reservations, orphan image files';

    public function handle(ReservationRetention $retention, ManagerReservationPurger $purger, GroupReservationPurger $groupPurger, WishImageStore $images, ProfilePhotoStore $photos, ShoppingImageStore $shoppingImages, RecipeImageStore $recipeImages): int
    {
        $reservations = $retention->purgeArchived((int) config('wishlist.reservation_retention_days'));
        $purger->purgeAll();
        $groupPurger->purgeAll();
        $files = $images->sweepOrphans(60);
        $photoFiles = $photos->sweepOrphans(60);
        $shoppingFiles = $shoppingImages->sweepOrphans(60);
        $recipeFiles = $recipeImages->sweepOrphans(60);

        $this->info("Pruned {$reservations} reservations of received wishes and {$files} orphan image files and {$photoFiles} orphan profile photo files and {$shoppingFiles} orphan shopping image files and {$recipeFiles} orphan recipe photo files and group reservations swept.");

        return 0;
    }
}
