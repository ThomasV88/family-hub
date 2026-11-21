<?php

use App\Http\Controllers\Wishlist\ListController;
use App\Http\Controllers\Wishlist\Owner\ReceivedController;
use App\Http\Controllers\Wishlist\Owner\ShareTargetController;
use App\Http\Controllers\Wishlist\Owner\WishController;
use Illuminate\Support\Facades\Route;

Route::get('/wishlist', [ListController::class, 'index'])
    ->middleware('access:module,wishlist,view')->name('wishlist.index');
// Web Share Target (manifest share_target): flashes a prefill and redirects to the new-wish form; saves nothing.
Route::get('/wishlist/share', ShareTargetController::class)
    ->middleware(['access:module,wishlist,edit', 'throttle:30,1,wishlist.share'])->name('wishlist.share');
Route::post('/wishlist/wishes', [WishController::class, 'store'])
    ->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.wishes.store'])->name('wishlist.wishes.store');
Route::patch('/wishlist/wishes/{wish}', [WishController::class, 'update'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:120,1,wishlist.wishes.update'])->name('wishlist.wishes.update');
Route::delete('/wishlist/wishes/{wish}', [WishController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.wishes.destroy'])->name('wishlist.wishes.destroy');
Route::post('/wishlist/wishes/{wish}/received', [ReceivedController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.wishes.received.store'])->name('wishlist.wishes.received.store');
Route::delete('/wishlist/wishes/{wish}/received', [ReceivedController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.wishes.received.destroy'])->name('wishlist.wishes.received.destroy');
