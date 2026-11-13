<?php

use App\Http\Controllers\Settings\MembersController;
use App\Http\Controllers\Settings\PermissionsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/members', [MembersController::class, 'index'])->middleware('access:admin')->name('settings.members');
Route::put('/settings/members/{user}/permissions', [PermissionsController::class, 'update'])
    ->middleware('access:admin')
    ->whereNumber('user')
    ->name('settings.members.permissions.update');
