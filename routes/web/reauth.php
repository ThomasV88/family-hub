<?php

use App\Http\Controllers\Security\ReauthController;
use Illuminate\Support\Facades\Route;

Route::get('/confirm-access', [ReauthController::class, 'show'])
    ->middleware('access:authenticated')->name('confirm-access');
Route::post('/confirm-access', [ReauthController::class, 'store'])
    ->middleware(['access:authenticated', 'throttle:reauth'])->name('confirm-access.store');
Route::post('/confirm-access/sign-in-again', [ReauthController::class, 'restart'])
    ->middleware('access:authenticated')->name('confirm-access.restart');
