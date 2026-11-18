<?php

use App\Http\Controllers\Health\UploadBatchController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): person, batch and item are resolved through the
// query layer, and every miss answers the same 403. Files go through health.documents.store with an upload_batch_id.
Route::post('/health/people/{person}/upload-batches', [UploadBatchController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:10,1,health.batches.store'])->name('health.batches.store');
Route::get('/health/people/{person}/upload-batches/{batch}', [UploadBatchController::class, 'show'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.batches.show'])->name('health.batches.show');
Route::get('/health/people/{person}/upload-batches/{batch}/status', [UploadBatchController::class, 'status'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.batches.status'])->name('health.batches.status');
Route::put('/health/people/{person}/upload-batches/{batch}/current', [UploadBatchController::class, 'current'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.batches.current'])->name('health.batches.current');
Route::put('/health/people/{person}/upload-batches/{batch}/items/{item}/choices', [UploadBatchController::class, 'choices'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.batches.choices'])->name('health.batches.choices');
Route::put('/health/people/{person}/upload-batches/{batch}/items/{item}/adjustments', [UploadBatchController::class, 'adjustments'])
    ->middleware(['access:module,health,edit', 'throttle:120,1,health.batches.adjustments'])->name('health.batches.adjustments');
Route::post('/health/people/{person}/upload-batches/{batch}/items/{item}/rescan', [UploadBatchController::class, 'rescan'])
    ->middleware(['access:module,health,edit', 'throttle:20,1,health.batches.rescan'])->name('health.batches.rescan');
Route::post('/health/people/{person}/upload-batches/{batch}/items/{item}/continue', [UploadBatchController::class, 'continue'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.batches.continue'])->name('health.batches.continue');
Route::delete('/health/people/{person}/upload-batches/{batch}/items/{item}', [UploadBatchController::class, 'removeItem'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.batches.items.destroy'])->name('health.batches.items.destroy');
Route::delete('/health/people/{person}/upload-batches/{batch}', [UploadBatchController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.batches.destroy'])->name('health.batches.destroy');
