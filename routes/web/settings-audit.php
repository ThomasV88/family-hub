<?php

use App\Http\Controllers\Settings\AuditController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/audit', [AuditController::class, 'index'])->middleware('access:admin')->name('settings.audit');
