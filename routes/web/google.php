<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->middleware(['access:guest', 'no-referrer', 'throttle:10,1'])->name('auth.google.redirect');
// Public: guests complete Google sign-in here; a signed-in member is sent home (no Google re-auth exists).
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->middleware(['access:public', 'throttle:10,1'])->name('auth.google.callback');
