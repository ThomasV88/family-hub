<?php

use App\Http\Controllers\Wishlist\Groups\GroupController;
use App\Http\Controllers\Wishlist\Groups\GroupMembersController;
use Illuminate\Support\Facades\Route;

Route::post('/wishlist/groups', [GroupController::class, 'store'])
    ->middleware(['access:module,wishlist,edit', 'throttle:30,1,wishlist.groups.store'])->name('wishlist.groups.store');
Route::patch('/wishlist/groups/{group}', [GroupController::class, 'update'])
    ->whereNumber('group')->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.groups.update'])->name('wishlist.groups.update');
Route::delete('/wishlist/groups/{group}', [GroupController::class, 'destroy'])
    ->whereNumber('group')->middleware(['access:module,wishlist,edit', 'throttle:30,1,wishlist.groups.destroy'])->name('wishlist.groups.destroy');
Route::put('/wishlist/groups/{group}/members', [GroupMembersController::class, 'update'])
    ->whereNumber('group')->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist.groups.members.update'])->name('wishlist.groups.members.update');
Route::delete('/wishlist/groups/{group}/membership', [GroupMembersController::class, 'destroy'])
    ->whereNumber('group')->middleware(['access:module,wishlist,view', 'throttle:60,1,wishlist.groups.leave'])->name('wishlist.groups.leave');
