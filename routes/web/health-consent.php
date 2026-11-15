<?php

use App\Http\Controllers\Health\AiConsentController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::patch('/health/people/{person}/ai-consent', [AiConsentController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.people.ai_consent.update'])->name('health.people.ai_consent.update');
