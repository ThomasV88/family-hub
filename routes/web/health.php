<?php

use App\Http\Controllers\Health\PersonController;
use Illuminate\Support\Facades\Route;

// No whereUuid and no route model binding on purpose: a malformed id, an unknown id and a hidden id must all answer
// the same 403 page (a route constraint would fall through to a 404 and tell the two apart). Controllers resolve the
// id through App\Medical\Queries and abort(403) on null.
Route::get('/health', [PersonController::class, 'index'])
    ->middleware('access:module,health,view')->name('health.index');
Route::get('/health/people/{person}', [PersonController::class, 'show'])
    ->middleware('access:module,health,view')->name('health.people.show');
Route::post('/health/people', [PersonController::class, 'store'])
    ->middleware(['access:module,health,edit', 'throttle:30,1,health.people.store'])->name('health.people.store');
Route::patch('/health/people/{person}', [PersonController::class, 'update'])
    ->middleware(['access:module,health,edit', 'throttle:60,1,health.people.update'])->name('health.people.update');
Route::delete('/health/people/{person}', [PersonController::class, 'destroy'])
    ->middleware(['access:module,health,edit', 'throttle:10,1,health.people.destroy'])->name('health.people.destroy');
