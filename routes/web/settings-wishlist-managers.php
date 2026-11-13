<?php

use App\Http\Controllers\Settings\WishlistManagersController;
use Illuminate\Support\Facades\Route;

Route::put('/settings/members/{user}/wishlist-managers', [WishlistManagersController::class, 'update'])
    ->middleware('access:admin')
    ->whereNumber('user')
    ->name('settings.members.wishlist_managers.update');
