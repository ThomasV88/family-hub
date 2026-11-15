<?php

use App\Http\Controllers\Finance\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/import', [ImportController::class, 'index'])
    ->middleware('access:module,finance,edit')->name('finance.import.index');
Route::post('/finance/import/preview', [ImportController::class, 'preview'])
    ->middleware(['access:module,finance,edit', 'throttle:30,1,finance.import.preview'])->name('finance.import.preview');
Route::post('/finance/import', [ImportController::class, 'store'])
    ->middleware(['access:module,finance,edit', 'throttle:10,1,finance.import.store'])->name('finance.import.store');
Route::get('/finance/import/{batch}', [ImportController::class, 'show'])
    ->whereUuid('batch')->middleware('access:module,finance,edit')->name('finance.import.show');
Route::post('/finance/import/{batch}/commit', [ImportController::class, 'commit'])
    ->whereUuid('batch')->middleware(['access:module,finance,edit', 'throttle:20,1,finance.import.commit'])->name('finance.import.commit');
Route::post('/finance/import/{batch}/undo', [ImportController::class, 'undo'])
    ->whereUuid('batch')->middleware(['access:module,finance,edit', 'throttle:20,1,finance.import.undo'])->name('finance.import.undo');
Route::delete('/finance/import/{batch}', [ImportController::class, 'discard'])
    ->whereUuid('batch')->middleware(['access:module,finance,edit', 'throttle:20,1,finance.import.discard'])->name('finance.import.discard');
