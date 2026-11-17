<?php

use App\Http\Controllers\Recipes\PhotoController;
use Illuminate\Support\Facades\Route;

// Recipe photos (private files). Recipes view to see, edit (and authorship) to change.
Route::get('/recipes/{recipe}/photos/{photo}/{size}', [PhotoController::class, 'show'])
    ->whereNumber('recipe')->whereNumber('photo')->whereIn('size', ['full', 'thumb'])
    ->middleware('access:module,recipes,view')->name('recipes.photos.show');
Route::post('/recipes/{recipe}/photos', [PhotoController::class, 'store'])
    ->whereNumber('recipe')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.photos.write'])->name('recipes.photos.store');
Route::put('/recipes/{recipe}/photos/{photo}/main', [PhotoController::class, 'main'])
    ->whereNumber('recipe')->whereNumber('photo')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.photos.write'])->name('recipes.photos.main');
Route::put('/recipes/{recipe}/photos/{photo}/position', [PhotoController::class, 'move'])
    ->whereNumber('recipe')->whereNumber('photo')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.photos.write'])->name('recipes.photos.move');
Route::delete('/recipes/{recipe}/photos/{photo}', [PhotoController::class, 'destroy'])
    ->whereNumber('recipe')->whereNumber('photo')->middleware(['access:module,recipes,edit', 'throttle:30,1,recipes.photos.write'])->name('recipes.photos.destroy');
