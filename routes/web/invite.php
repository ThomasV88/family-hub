<?php

use App\Http\Controllers\Auth\InviteAcceptController;
use Illuminate\Support\Facades\Route;

Route::get('/invite/{token}', [InviteAcceptController::class, 'show'])
    ->middleware(['access:guest', 'no-referrer', 'throttle:10,1'])->name('invite.show');
Route::post('/invite/{token}', [InviteAcceptController::class, 'store'])
    ->middleware(['access:guest', 'no-referrer', 'throttle:10,1'])->name('invite.accept');
