<?php

use App\Http\Controllers\Finance\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/transactions', [TransactionController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.transactions.index');
