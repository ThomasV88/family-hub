<?php

use App\Http\Controllers\Wishlist\Owner\LinkPreviewController;
use App\Http\Controllers\Wishlist\Owner\WishImageController;
use Illuminate\Support\Facades\Route;

Route::get('/wishlist/wishes/{wish}/image/{variant}', [WishImageController::class, 'show'])
    ->whereNumber('wish')->whereIn('variant', ['full', 'thumb'])
    ->middleware('access:module,wishlist,view')->name('wishlist.images.show');
Route::post('/wishlist/wishes/{wish}/image', [WishImageController::class, 'store'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:20,1,wishlist.images.store'])->name('wishlist.images.store');
Route::delete('/wishlist/wishes/{wish}/image', [WishImageController::class, 'destroy'])
    ->whereNumber('wish')->middleware('access:module,wishlist,edit')->name('wishlist.images.destroy');
Route::post('/wishlist/wishes/{wish}/image/fetch', [WishImageController::class, 'fetch'])
    ->whereNumber('wish')->middleware(['access:module,wishlist,edit', 'throttle:10,1,wishlist.images.fetch'])->name('wishlist.images.fetch');

Route::post('/wishlist/link-preview', [LinkPreviewController::class, 'start'])
    ->middleware(['access:module,wishlist,edit', 'throttle:15,1,wishlist.link_preview'])->name('wishlist.link-preview.start');
Route::get('/wishlist/link-preview/{token}', [LinkPreviewController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('access:module,wishlist,edit')->name('wishlist.link-preview.show');
Route::get('/wishlist/link-preview/{token}/image', [LinkPreviewController::class, 'image'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('access:module,wishlist,edit')->name('wishlist.link-preview.image');
