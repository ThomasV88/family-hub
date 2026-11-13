<?php

use App\Http\Controllers\Wishlist\Giving\CoBuyController;
use App\Http\Controllers\Wishlist\Giving\ContributionController;
use App\Http\Controllers\Wishlist\Giving\ReservationController;
use App\Http\Controllers\Wishlist\Giving\ShoppingController;
use Illuminate\Support\Facades\Route;

Route::post('/wishlist/wishes/{wish}/reservation', [ReservationController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.reserve');
Route::delete('/wishlist/wishes/{wish}/reservation', [ReservationController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.cancel');
Route::post('/wishlist/wishes/{wish}/reservation/cobuy', [CoBuyController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.cobuy.open');
Route::delete('/wishlist/wishes/{wish}/reservation/cobuy', [CoBuyController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.cobuy.close');
Route::put('/wishlist/wishes/{wish}/reservation/contribution', [ContributionController::class, 'update'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.contribution.update');
Route::delete('/wishlist/wishes/{wish}/reservation/contribution', [ContributionController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.contribution.destroy');
Route::get('/wishlist/shopping', [ShoppingController::class, 'index'])
    ->middleware(['access:module,wishlist,view', 'throttle:60,1'])->name('wishlist.giving.shopping');
