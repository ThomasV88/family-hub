<?php

use App\Http\Controllers\Settings\HelpTextsController;
use Illuminate\Support\Facades\Route;

// Contextual help management (admin only). Writes need the admin role; they grant nothing and expose no data, so there is
// no access:recent (a stale session can still be refused by the central dialog for other routes; see the issue 4 notes).
$key = '[a-z0-9]+(?:[._-][a-z0-9]+)*';

Route::get('/settings/help', [HelpTextsController::class, 'index'])->middleware('access:admin')->name('settings.help');
Route::get('/settings/help/{key}', [HelpTextsController::class, 'show'])->where('key', $key)
    ->middleware(['access:admin', 'throttle:120,1,settings.help.show'])->name('settings.help.show');
Route::put('/settings/help/{key}', [HelpTextsController::class, 'update'])->where('key', $key)
    ->middleware(['access:admin', 'throttle:60,1,settings.help.update'])->name('settings.help.update');
