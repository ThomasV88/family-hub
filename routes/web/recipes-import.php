<?php

use App\Http\Controllers\Recipes\ImportController;
use Illuminate\Support\Facades\Route;

// AI import of a recipe from a web page. Gate first (Recipes edit), then the service scopes every id to the caller:
// another member's import and an unknown id answer the same 404.
Route::post('/recipes/imports/notice', [ImportController::class, 'notice'])
    ->middleware(['access:module,recipes,edit', 'throttle:10,1,recipes.imports.notice'])->name('recipes.imports.notice');
Route::post('/recipes/imports', [ImportController::class, 'store'])
    ->middleware(['access:module,recipes,edit', 'throttle:10,1,recipes.imports.start'])->name('recipes.imports.store');
Route::get('/recipes/imports/{import}', [ImportController::class, 'show'])
    ->whereUuid('import')->middleware(['access:module,recipes,edit', 'throttle:120,1,recipes.imports.status'])->name('recipes.imports.show');
Route::post('/recipes/imports/{import}/cancel', [ImportController::class, 'cancel'])
    ->whereUuid('import')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.imports.cancel'])->name('recipes.imports.cancel');
Route::delete('/recipes/imports/{import}', [ImportController::class, 'destroy'])
    ->whereUuid('import')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.imports.cancel'])->name('recipes.imports.destroy');
