<?php

use App\Http\Controllers\Health\AllergyController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::post('/health/people/{person}/allergies', [AllergyController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.allergies.store'])->name('health.allergies.store');
Route::patch('/health/people/{person}/allergies/{allergy}', [AllergyController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.allergies.update'])->name('health.allergies.update');
Route::delete('/health/people/{person}/allergies/{allergy}', [AllergyController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.allergies.destroy'])->name('health.allergies.destroy');
