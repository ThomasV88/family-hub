<?php

use App\Http\Controllers\Health\DocumentSearchController;
use App\Http\Controllers\Health\DocumentTextController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same way. The search is a
// POST so the query never appears in a URL, a history entry or an access log; it is a read (see RequireAccess).
Route::post('/health/search', [DocumentSearchController::class, 'search'])
    ->middleware(['access:module,health,view', 'throttle:30,1,health.search'])->name('health.search');

// The recognised text of a document: the same access as the document itself (view reads, edit removes).
Route::get('/health/people/{person}/documents/{document}/text', [DocumentTextController::class, 'show'])
    ->middleware(['access:module,health,view', 'throttle:60,1,health.documents.text'])->name('health.documents.text');
Route::delete('/health/people/{person}/documents/{document}/text', [DocumentTextController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.documents.text.destroy'])->name('health.documents.text.destroy');
