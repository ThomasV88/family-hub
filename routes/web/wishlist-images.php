<?php

use App\Http\Controllers\Wishlist\Owner\WishImageController;
use Illuminate\Support\Facades\Route;

Route::get('/wishlist/wishes/{wish}/image/{variant}', [WishImageController::class, 'show'])
    ->whereNumber('wish')->whereIn('variant', ['full', 'thumb'])
    ->middleware('access:module,wishlist,view')->name('wishlist.images.show');
Route::post('/wishlist/wishes/{wish}/image', [WishImageController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:20,1'])->name('wishlist.images.store');
Route::delete('/wishlist/wishes/{wish}/image', [WishImageController::class, 'destroy'])
    ->whereNumber('wish')->middleware('access:module,wishlist,edit')->name('wishlist.images.destroy');
Route::post('/wishlist/wishes/{wish}/image/fetch', [WishImageController::class, 'fetch'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:10,1'])->name('wishlist.images.fetch');
