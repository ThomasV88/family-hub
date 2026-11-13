<?php

use App\Http\Controllers\Finance\CategoryController;
use Illuminate\Support\Facades\Route;

Route::get('/finance/categories', [CategoryController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.categories.index');
Route::post('/finance/categories', [CategoryController::class, 'store'])
    ->middleware(['access:module,finance,edit', 'throttle:60,1,finance.categories.store'])->name('finance.categories.store');
Route::patch('/finance/categories/{category}', [CategoryController::class, 'update'])
    ->whereUuid('category')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.categories.update'])->name('finance.categories.update');
Route::delete('/finance/categories/{category}', [CategoryController::class, 'destroy'])
    ->whereUuid('category')->middleware(['access:module,finance,edit', 'throttle:60,1,finance.categories.destroy'])->name('finance.categories.destroy');
