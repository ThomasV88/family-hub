<?php

use App\Http\Controllers\Settings\ShoppingCatalogController;
use App\Http\Controllers\Settings\ShoppingSettingsController;
use App\Http\Controllers\Settings\ShoppingStoresController;
use App\Http\Controllers\Settings\ShoppingUnitsController;
use Illuminate\Support\Facades\Route;

// Shopping settings writes. Catalog and stores: any member with Shopping edit. Units: administrator with Shopping edit.
Route::prefix('/settings/shopping')->group(function () {
    Route::get('/', [ShoppingSettingsController::class, 'index'])->middleware('access:module,shopping,edit')->name('settings.shopping');

    Route::middleware('access:module,shopping,edit')->group(function () {
        Route::post('/catalog', [ShoppingCatalogController::class, 'store'])->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.catalog.store');
        Route::put('/catalog/{item}', [ShoppingCatalogController::class, 'update'])->whereNumber('item')->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.catalog.update');
        Route::delete('/catalog/{item}', [ShoppingCatalogController::class, 'destroy'])->whereNumber('item')->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.catalog.destroy');

        Route::post('/stores', [ShoppingStoresController::class, 'store'])->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.stores.store');
        Route::put('/stores/{store}', [ShoppingStoresController::class, 'update'])->whereNumber('store')->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.stores.update');
        Route::delete('/stores/{store}', [ShoppingStoresController::class, 'destroy'])->whereNumber('store')->middleware('throttle:60,1,shopping.settings.write')->name('settings.shopping.stores.destroy');
    });

    Route::middleware(['access:admin', 'access:module,shopping,edit'])->group(function () {
        Route::post('/units', [ShoppingUnitsController::class, 'store'])->name('settings.shopping.units.store');
        Route::put('/units/{unit}', [ShoppingUnitsController::class, 'update'])->whereNumber('unit')->name('settings.shopping.units.update');
        Route::delete('/units/{unit}', [ShoppingUnitsController::class, 'destroy'])->whereNumber('unit')->name('settings.shopping.units.destroy');
    });
});
