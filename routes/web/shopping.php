<?php

use App\Http\Controllers\Shopping\CopyController;
use App\Http\Controllers\Shopping\FinishController;
use App\Http\Controllers\Shopping\ListController;
use App\Http\Controllers\Shopping\LockController;
use Illuminate\Support\Facades\Route;

Route::get('/shopping', [ListController::class, 'index'])
    ->middleware('access:module,shopping,view')->name('shopping.index');
Route::post('/shopping/lists', [ListController::class, 'store'])
    ->middleware(['access:module,shopping,edit', 'throttle:60,1'])->name('shopping.lists.store');
Route::get('/shopping/lists/{list}', [ListController::class, 'show'])
    ->whereNumber('list')->middleware('access:module,shopping,view')->name('shopping.lists.show');
Route::put('/shopping/lists/{list}', [ListController::class, 'update'])
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:120,1'])->name('shopping.lists.update');
Route::delete('/shopping/lists/{list}', [ListController::class, 'destroy'])
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:60,1'])->name('shopping.lists.destroy');
Route::put('/shopping/lists/{list}/lock', LockController::class)
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:60,1'])->name('shopping.lists.lock');
Route::post('/shopping/lists/{list}/finish', FinishController::class)
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:60,1'])->name('shopping.lists.finish');
Route::post('/shopping/lists/{list}/copy', CopyController::class)
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:60,1'])->name('shopping.lists.copy');
