<?php

use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\StyleGuideController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('heartbeat.store');
Route::post('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

// Always registered (so route:cache cannot bake in the build environment); the controller enforces the allowlist.
Route::get('/_style', StyleGuideController::class)->name('style');
