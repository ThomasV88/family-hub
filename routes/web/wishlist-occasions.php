<?php

use App\Http\Controllers\Wishlist\Owner\MemberDatesController;
use App\Http\Controllers\Wishlist\Owner\NameDayController;
use App\Http\Controllers\Wishlist\Owner\OccasionController;
use Illuminate\Support\Facades\Route;

Route::get('/wishlist/occasions', [OccasionController::class, 'index'])
    ->middleware('access:module,wishlist,view')->name('wishlist.occasions.index');
Route::post('/wishlist/occasions', [OccasionController::class, 'store'])
    ->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.occasions.store');
Route::patch('/wishlist/occasions/{occasion}', [OccasionController::class, 'update'])
    ->whereNumber('occasion')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.occasions.update');
Route::delete('/wishlist/occasions/{occasion}', [OccasionController::class, 'destroy'])
    ->whereNumber('occasion')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.occasions.destroy');
Route::put('/wishlist/members/{member}/dates', [MemberDatesController::class, 'update'])
    ->whereNumber('member')->middleware(['access:module,wishlist,edit', 'throttle:60,1'])->name('wishlist.members.dates.update');
Route::get('/wishlist/namedays', [NameDayController::class, 'search'])
    ->middleware(['access:module,wishlist,view', 'throttle:120,1'])->name('wishlist.namedays.search');
