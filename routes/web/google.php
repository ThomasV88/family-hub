<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->middleware(['access:guest', 'no-referrer', 'throttle:10,1'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->middleware(['access:guest', 'throttle:10,1'])->name('auth.google.callback');
