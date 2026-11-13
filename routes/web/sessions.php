<?php

use App\Http\Controllers\Settings\SessionsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/members/{user}/sessions', [SessionsController::class, 'index'])
    ->middleware('access:admin')->whereNumber('user')->name('settings.members.sessions.index');
Route::delete('/settings/members/{user}/sessions/{session}', [SessionsController::class, 'destroy'])
    ->middleware('access:admin')->whereNumber('user')->where('session', '[A-Za-z0-9]{40}')->name('settings.members.sessions.destroy');
Route::delete('/settings/members/{user}/sessions', [SessionsController::class, 'destroyAll'])
    ->middleware('access:admin')->whereNumber('user')->name('settings.members.sessions.destroy_all');
