<?php

use App\Http\Controllers\ManualController;
use Illuminate\Support\Facades\Route;

Route::get('/manual', ManualController::class)->middleware('access:authenticated')->name('manual.index');
