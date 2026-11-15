<?php

use App\Http\Controllers\Health\ArchiveController;
use Illuminate\Support\Facades\Route;

// Restore of an archived person or record (the delete routes archive instead of deleting once the record is older than
// the delete window). No whereUuid and no route model binding (see routes/web/health.php): every miss answers the same 403.
// The archive itself is listed in the Health/Person page props for members with Edit; there is no GET route.
Route::post('/health/people/{person}/restore', [ArchiveController::class, 'restorePerson'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.people.restore'])->name('health.people.restore');
Route::post('/health/people/{person}/archive/{type}/{record}/restore', [ArchiveController::class, 'restoreRecord'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.archive.restore'])->name('health.archive.restore');
