<?php

use App\Http\Controllers\Finance\BillController;
use Illuminate\Support\Facades\Route;

Route::post('/finance/recurring/{recurring}/bills/{date}/record', [BillController::class, 'record'])
    ->whereUuid('recurring')->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.bills.record'])->name('finance.bills.record');
Route::post('/finance/recurring/{recurring}/bills/{date}/match', [BillController::class, 'match'])
    ->whereUuid('recurring')->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.bills.match'])->name('finance.bills.match');
Route::delete('/finance/recurring/{recurring}/bills/{date}/match', [BillController::class, 'unmatch'])
    ->whereUuid('recurring')->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.bills.unmatch'])->name('finance.bills.unmatch');
