<?php

use App\Http\Controllers\Settings\ChildrenController;
use Illuminate\Support\Facades\Route;

// Creates an account with Todo and Wishlist edit grants and names who manages its list, so it needs a recent
// confirmation on top of admin (SensitiveRoutes::accessGranting()).
Route::post('/settings/members/children', [ChildrenController::class, 'store'])
    ->middleware(['access:admin', 'access:recent', 'throttle:10,1,settings.members.children.store'])
    ->name('settings.members.children.store');
