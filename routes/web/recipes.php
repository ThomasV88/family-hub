<?php

use App\Http\Controllers\Recipes\RecipeController;
use App\Http\Controllers\Recipes\VisibilityController;
use Illuminate\Support\Facades\Route;

// Recipe book. Module gate first (view reads, edit authors), record rules (author only, hidden = 404) in the services.
Route::get('/recipes', [RecipeController::class, 'index'])->middleware('access:module,recipes,view')->name('recipes.index');
Route::get('/recipes/create', [RecipeController::class, 'create'])->middleware('access:module,recipes,edit')->name('recipes.create');
Route::post('/recipes', [RecipeController::class, 'store'])
    ->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.write'])->name('recipes.store');
Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])
    ->whereNumber('recipe')->middleware('access:module,recipes,view')->name('recipes.show');
Route::get('/recipes/{recipe}/edit', [RecipeController::class, 'edit'])
    ->whereNumber('recipe')->middleware('access:module,recipes,edit')->name('recipes.edit');
Route::put('/recipes/{recipe}', [RecipeController::class, 'update'])
    ->whereNumber('recipe')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.write'])->name('recipes.update');
Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy'])
    ->whereNumber('recipe')->middleware('access:module,recipes,edit')->name('recipes.destroy');
Route::put('/recipes/{recipe}/visibility', VisibilityController::class)
    ->whereNumber('recipe')->middleware('access:module,recipes,edit')->name('recipes.visibility');
