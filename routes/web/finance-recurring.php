<?php

use App\Http\Controllers\Finance\RecurringController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/recurring', [RecurringController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.recurring.index');
Route::post('/finance/recurring', [RecurringController::class, 'store'])
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.recurring.store'])->name('finance.recurring.store');
Route::patch('/finance/recurring/{recurring}', [RecurringController::class, 'update'])
    ->whereUuid('recurring')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.recurring.update'])->name('finance.recurring.update');
Route::delete('/finance/recurring/{recurring}', [RecurringController::class, 'destroy'])
    ->whereUuid('recurring')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.recurring.destroy'])->name('finance.recurring.destroy');
