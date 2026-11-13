<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->middleware(['access:guest', 'no-referrer', 'throttle:10,1'])->name('auth.google.redirect');
// Public: a signed-in member returns here from the Google re-auth; the controller branches.
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->middleware(['access:public', 'throttle:10,1'])->name('auth.google.callback');
Route::get('/confirm-access/google', [GoogleController::class, 'reauthRedirect'])
    ->middleware(['access:authenticated', 'no-referrer', 'throttle:10,1'])->name('confirm-access.google');
