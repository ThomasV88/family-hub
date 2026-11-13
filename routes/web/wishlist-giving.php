<?php

use App\Http\Controllers\Wishlist\Giving\ReservationController;
use Illuminate\Support\Facades\Route;

Route::post('/wishlist/wishes/{wish}/reservation', [ReservationController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.reserve');
Route::delete('/wishlist/wishes/{wish}/reservation', [ReservationController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.giving.cancel');
