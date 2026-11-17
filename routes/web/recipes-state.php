<?php

use App\Http\Controllers\Recipes\StateController;
use Illuminate\Support\Facades\Route;

// Per-member marks on a visible recipe: Recipes view is enough (they only touch the actor's own rows).
$state = ['access:module,recipes,view', 'throttle:240,1,recipes.state'];

Route::put('/recipes/{recipe}/ingredients/{ingredient}/tick', [StateController::class, 'tick'])
    ->whereNumber(['recipe', 'ingredient'])->middleware($state)->name('recipes.ingredients.tick');
Route::put('/recipes/{recipe}/steps/{step}/done', [StateController::class, 'step'])
    ->whereNumber(['recipe', 'step'])->middleware($state)->name('recipes.steps.done');
Route::put('/recipes/{recipe}/favourite', [StateController::class, 'favourite'])
    ->whereNumber('recipe')->middleware($state)->name('recipes.favourite');
Route::put('/recipes/{recipe}/note', [StateController::class, 'note'])
    ->whereNumber('recipe')->middleware($state)->name('recipes.note');
Route::put('/recipes/{recipe}/servings', [StateController::class, 'servings'])
    ->whereNumber('recipe')->middleware($state)->name('recipes.servings');
Route::delete('/recipes/{recipe}/progress', [StateController::class, 'reset'])
    ->whereNumber('recipe')->middleware($state)->name('recipes.progress.reset');
