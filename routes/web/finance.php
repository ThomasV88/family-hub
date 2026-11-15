<?php

use App\Http\Controllers\Finance\OverviewController;
use Illuminate\Support\Facades\Route;

Route::get('/finance', [OverviewController::class, 'index'])
    ->middleware('access:module,finance,view')->name('finance.index');
