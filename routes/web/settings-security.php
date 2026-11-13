<?php

use App\Http\Controllers\Security\SecuritySettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/security', [SecuritySettingsController::class, 'show'])
    ->middleware('access:authenticated')->name('settings.security');
Route::post('/settings/security/continue', [SecuritySettingsController::class, 'continue'])
    ->middleware('access:authenticated')->name('settings.security.continue');
