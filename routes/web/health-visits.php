<?php

use App\Http\Controllers\Health\VisitController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
Route::post('/health/people/{person}/visits', [VisitController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.visits.store'])->name('health.visits.store');
Route::patch('/health/people/{person}/visits/{visit}', [VisitController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.visits.update'])->name('health.visits.update');
Route::delete('/health/people/{person}/visits/{visit}', [VisitController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.visits.destroy'])->name('health.visits.destroy');
