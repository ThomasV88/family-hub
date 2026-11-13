<?php

use App\Http\Controllers\Finance\AccountsController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/accounts', [AccountsController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.accounts.index');
