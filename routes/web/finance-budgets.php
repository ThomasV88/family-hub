<?php

use App\Http\Controllers\Finance\BudgetController;
use Illuminate\Support\Facades\Route;

Route::post('/finance/budgets', [BudgetController::class, 'store'])
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.budgets.store'])->name('finance.budgets.store');
Route::patch('/finance/budgets/{budget}', [BudgetController::class, 'update'])
    ->whereUuid('budget')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.budgets.update'])->name('finance.budgets.update');
Route::delete('/finance/budgets/{budget}', [BudgetController::class, 'destroy'])
    ->whereUuid('budget')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.budgets.destroy'])->name('finance.budgets.destroy');
