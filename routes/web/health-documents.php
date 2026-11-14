<?php

use App\Http\Controllers\Health\DocumentController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
// nginx gives exactly this URL a 12m body limit (docker/nginx/snippets/laravel-locations.conf).
Route::post('/health/people/{person}/documents', [DocumentController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.documents.store'])->name('health.documents.store');
Route::get('/health/people/{person}/documents/{document}/preview/{variant}', [DocumentController::class, 'preview'])
    ->middleware('access:module,health,view')->name('health.documents.preview');
Route::get('/health/people/{person}/documents/{document}/file', [DocumentController::class, 'file'])
    ->middleware('access:module,health,view')->name('health.documents.file');
Route::patch('/health/people/{person}/documents/{document}', [DocumentController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.documents.update'])->name('health.documents.update');
Route::delete('/health/people/{person}/documents/{document}', [DocumentController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.documents.destroy'])->name('health.documents.destroy');
