<?php

use App\Http\Controllers\Health\DocumentController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
// nginx gives exactly this URL a 12m body limit (docker/nginx/snippets/laravel-locations.conf).
Route::post('/health/people/{person}/documents', [DocumentController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.documents.store'])->name('health.documents.store');
