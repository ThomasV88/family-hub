<?php

use App\Http\Controllers\Health\AiRunController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::post('/health/people/{person}/documents/{document}/ai', [AiRunController::class, 'run'])
    ->middleware(['access:module,health,edit', 'throttle:10,1,health.documents.ai.run'])->name('health.documents.ai.run');
Route::post('/health/people/{person}/ai/{extraction}/cancel', [AiRunController::class, 'cancel'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.ai.cancel'])->name('health.ai.cancel');
Route::get('/health/people/{person}/ai-status', [AiRunController::class, 'status'])
    ->middleware(['access:module,health,view', 'throttle:60,1,health.people.ai.status'])->name('health.people.ai.status');
