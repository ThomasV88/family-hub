<?php

use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\StyleGuideController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware('access:authenticated')->name('home');
Route::post('/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware(['access:authenticated', 'throttle:6,1'])
    ->name('heartbeat.store');
Route::post('/locale', [LocaleController::class, 'update'])
    ->middleware(['access:public', 'throttle:30,1'])
    ->name('locale.update');

// Always registered (so route:cache cannot bake in the build environment); the controller enforces the allowlist.
Route::get('/_style', StyleGuideController::class)->middleware('access:public')->name('style');

// Each feature plan adds its own file under routes/web/ so parallel plans never edit the same route file.
foreach (glob(__DIR__.'/web/*.php') ?: [] as $routeFile) {
    require $routeFile;
}
