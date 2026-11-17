<?php

use App\Http\Controllers\Shopping\ItemController;
use App\Http\Controllers\Shopping\ItemStateController;
use Illuminate\Support\Facades\Route;

Route::post('/shopping/lists/{list}/items', [ItemController::class, 'store'])
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:120,1'])->name('shopping.items.store');
Route::put('/shopping/lists/{list}/items/{item}', [ItemController::class, 'update'])
    ->whereNumber(['list', 'item'])->middleware(['access:module,shopping,edit', 'throttle:240,1'])->name('shopping.items.update');
Route::delete('/shopping/lists/{list}/items/{item}', [ItemController::class, 'destroy'])
    ->whereNumber(['list', 'item'])->middleware(['access:module,shopping,edit', 'throttle:240,1'])->name('shopping.items.destroy');
Route::put('/shopping/lists/{list}/items/{item}/state', ItemStateController::class)
    ->whereNumber(['list', 'item'])->middleware(['access:module,shopping,edit', 'throttle:240,1,shopping.items.state'])->name('shopping.items.state');
