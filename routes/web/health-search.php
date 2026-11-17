<?php

use App\Http\Controllers\Health\DocumentSearchController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same way. The search is a
// POST so the query never appears in a URL, a history entry or an access log; it is a read (see RequireAccess).
Route::post('/health/search', [DocumentSearchController::class, 'search'])
    ->middleware(['access:module,health,view', 'throttle:30,1,health.search'])->name('health.search');
