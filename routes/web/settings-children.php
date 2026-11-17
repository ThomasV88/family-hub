<?php

use App\Http\Controllers\Settings\ChildrenController;
use Illuminate\Support\Facades\Route;

// Creates an account with Todo and Wishlist edit grants and names who manages its list, so it needs a recent
// confirmation on top of admin (SensitiveRoutes::accessGranting()).
Route::post('/settings/members/children', [ChildrenController::class, 'store'])
    ->middleware(['access:admin', 'access:recent', 'throttle:10,1,settings.members.children.store'])
    ->name('settings.members.children.store');

// Gives an existing account a sign-in path (an invitation to an e-mail address), so it needs a recent confirmation too.
Route::post('/settings/members/{user}/convert', [ChildrenController::class, 'convert'])
    ->whereNumber('user')
    ->middleware(['access:admin', 'access:recent', 'throttle:30,1'])
    ->name('settings.members.children.convert');
