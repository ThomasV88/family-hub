<?php

use App\Http\Controllers\Settings\AiCallsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/ai-calls', [AiCallsController::class, 'index'])->middleware('access:admin')->name('settings.ai_calls');
Route::post('/settings/ai-calls/check', [AiCallsController::class, 'check'])
    ->middleware(['access:admin', 'access:recent', 'throttle:6,1,settings.ai_calls.check'])->name('settings.ai_calls.check');
