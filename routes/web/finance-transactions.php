<?php

use App\Http\Controllers\Finance\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/transactions', [TransactionController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.transactions.index');
Route::post('/finance/transactions', [TransactionController::class, 'store'])
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.transactions.store'])->name('finance.transactions.store');
Route::patch('/finance/transactions/{transaction}', [TransactionController::class, 'update'])
    ->whereUuid('transaction')->middleware(['access:module,finance,edit', 'throttle:120,1,finance.transactions.update'])->name('finance.transactions.update');
Route::delete('/finance/transactions/{transaction}', [TransactionController::class, 'destroy'])
    ->whereUuid('transaction')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.transactions.destroy'])->name('finance.transactions.destroy');
