<?php

use App\Http\Controllers\Wishlist\ListController;
use App\Http\Controllers\Wishlist\Owner\ReceivedController;
use App\Http\Controllers\Wishlist\Owner\WishController;
use Illuminate\Support\Facades\Route;

Route::get('/wishlist', [ListController::class, 'index'])
    ->middleware('access:module,wishlist,view')->name('wishlist.index');
Route::post('/wishlist/wishes', [WishController::class, 'store'])
    ->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.wishes.store');
Route::patch('/wishlist/wishes/{wish}', [WishController::class, 'update'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:120,1'])->name('wishlist.wishes.update');
Route::delete('/wishlist/wishes/{wish}', [WishController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.wishes.destroy');
Route::post('/wishlist/wishes/{wish}/received', [ReceivedController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.wishes.received.store');
Route::delete('/wishlist/wishes/{wish}/received', [ReceivedController::class, 'destroy'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.wishes.received.destroy');
