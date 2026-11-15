<?php

use App\Http\Controllers\Health\RecordVerifyController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::post('/health/people/{person}/visits/{visit}/verify', [RecordVerifyController::class, 'visit'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.visits.verify'])->name('health.visits.verify');
Route::post('/health/people/{person}/medications/{medication}/verify', [RecordVerifyController::class, 'medication'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.medications.verify'])->name('health.medications.verify');
Route::post('/health/people/{person}/allergies/{allergy}/verify', [RecordVerifyController::class, 'allergy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.allergies.verify'])->name('health.allergies.verify');
Route::post('/health/people/{person}/vaccinations/{vaccination}/verify', [RecordVerifyController::class, 'vaccination'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.vaccinations.verify'])->name('health.vaccinations.verify');
