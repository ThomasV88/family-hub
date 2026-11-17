<?php

use App\Http\Controllers\Shopping\ImageController;
use Illuminate\Support\Facades\Route;

// Catalog item pictures and store logos (private files; Shopping view to see, edit to change).
Route::get('/shopping/catalog/{item}/image/{variant}', [ImageController::class, 'showCatalog'])
    ->whereNumber('item')->whereIn('variant', ['full', 'thumb'])
    ->middleware('access:module,shopping,view')->name('shopping.catalog.image.show');
Route::post('/shopping/catalog/{item}/image', [ImageController::class, 'storeCatalog'])
    ->whereNumber('item')->middleware(['access:module,shopping,edit', 'throttle:20,1,shopping.images.store'])->name('shopping.catalog.image.store');
Route::delete('/shopping/catalog/{item}/image', [ImageController::class, 'destroyCatalog'])
    ->whereNumber('item')->middleware('access:module,shopping,edit')->name('shopping.catalog.image.destroy');

Route::get('/shopping/stores/{store}/image/{variant}', [ImageController::class, 'showStore'])
    ->whereNumber('store')->whereIn('variant', ['full', 'thumb'])
    ->middleware('access:module,shopping,view')->name('shopping.stores.image.show');
Route::post('/shopping/stores/{store}/image', [ImageController::class, 'storeStore'])
    ->whereNumber('store')->middleware(['access:module,shopping,edit', 'throttle:20,1,shopping.images.store'])->name('shopping.stores.image.store');
Route::delete('/shopping/stores/{store}/image', [ImageController::class, 'destroyStore'])
    ->whereNumber('store')->middleware('access:module,shopping,edit')->name('shopping.stores.image.destroy');
