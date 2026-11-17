<?php

use App\Http\Controllers\Recipes\ShoppingController;
use Illuminate\Support\Facades\Route;

// The button needs a visible recipe (Recipes view) and the right to add to lists (Shopping edit).
$gates = ['access:module,recipes,view', 'access:module,shopping,edit'];

Route::get('/recipes/{recipe}/shopping', [ShoppingController::class, 'preview'])
    ->whereNumber('recipe')->middleware($gates)->name('recipes.shopping.preview');
Route::post('/recipes/{recipe}/shopping', [ShoppingController::class, 'store'])
    ->whereNumber('recipe')->middleware([...$gates, 'throttle:20,1,recipes.shopping'])->name('recipes.shopping.store');
