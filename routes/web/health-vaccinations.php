<?php

use App\Http\Controllers\Health\VaccinationController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403. The
// {vaccination} parameter carries the PUBLIC uuid; the internal bigint id never appears in a URL.
Route::post('/health/people/{person}/vaccinations', [VaccinationController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.vaccinations.store'])->name('health.vaccinations.store');
Route::patch('/health/people/{person}/vaccinations/{vaccination}', [VaccinationController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.vaccinations.update'])->name('health.vaccinations.update');
Route::delete('/health/people/{person}/vaccinations/{vaccination}', [VaccinationController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.vaccinations.destroy'])->name('health.vaccinations.destroy');
Route::post('/health/people/{person}/vaccinations/{vaccination}/given', [VaccinationController::class, 'given'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.vaccinations.given'])->name('health.vaccinations.given');
