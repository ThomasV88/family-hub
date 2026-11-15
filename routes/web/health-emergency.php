<?php

use App\Http\Controllers\Health\EmergencyCardController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403. View level is
// enough (provisional decision); the controller audits before it builds the card. Never cacheable.
Route::get('/health/people/{person}/emergency-card', [EmergencyCardController::class, 'show'])
    ->middleware(['access:module,health,view', 'throttle:60,1,health.emergency.show', 'cache.headers:no_store;private'])
    ->name('health.emergency.show');
