<?php

use App\Http\Controllers\Settings\FinanceAccountsController;
use Illuminate\Support\Facades\Route;

Route::prefix('/settings/finance-accounts')->group(function () {
    Route::get('/', [FinanceAccountsController::class, 'index'])->middleware('access:admin')->name('settings.finance_accounts.index');
    // Create and update change which members can see an account's money (visibility): recent confirmation needed.
    Route::middleware(['access:admin', 'access:recent'])->group(function () {
        Route::post('/', [FinanceAccountsController::class, 'store'])->name('settings.finance_accounts.store');
        Route::patch('/{account}', [FinanceAccountsController::class, 'update'])->whereUuid('account')->name('settings.finance_accounts.update');
    });
});
