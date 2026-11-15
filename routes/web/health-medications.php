<?php

use App\Http\Controllers\Health\MedicationController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::post('/health/people/{person}/medications', [MedicationController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.medications.store'])->name('health.medications.store');
Route::patch('/health/people/{person}/medications/{medication}', [MedicationController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.medications.update'])->name('health.medications.update');
Route::delete('/health/people/{person}/medications/{medication}', [MedicationController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.medications.destroy'])->name('health.medications.destroy');
