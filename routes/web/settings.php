<?php

use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->middleware('access:authenticated')->name('settings.index');
Route::get('/settings/appearance', [SettingsController::class, 'appearance'])->middleware('access:authenticated')->name('settings.appearance');
