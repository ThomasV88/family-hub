<?php

use App\Http\Controllers\Health\ExportController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403. Exports need
// "can change"; the print and download routes write the audit row before anything is generated.
Route::get('/health/people/{person}/export', [ExportController::class, 'show'])
    ->middleware('access:module,health,edit')->name('health.exports.show');
Route::get('/health/people/{person}/export/print', [ExportController::class, 'print'])
    ->middleware(['access:module,health,edit', 'throttle:20,1,health.exports.print'])->name('health.exports.print');
Route::get('/health/people/{person}/export/download', [ExportController::class, 'download'])
    ->middleware(['access:module,health,edit', 'throttle:10,1,health.exports.download'])->name('health.exports.download');
