<?php

use App\Http\Controllers\Settings\FinanceAccountsController;
use Illuminate\Support\Facades\Route;

Route::middleware('access:admin')->prefix('/settings/finance-accounts')->group(function () {
    Route::get('/', [FinanceAccountsController::class, 'index'])->name('settings.finance_accounts.index');
    Route::post('/', [FinanceAccountsController::class, 'store'])->name('settings.finance_accounts.store');
    Route::patch('/{account}', [FinanceAccountsController::class, 'update'])->whereUuid('account')->name('settings.finance_accounts.update');
});
