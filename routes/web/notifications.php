<?php

use App\Http\Controllers\Settings\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/settings/notifications/push', [PushSubscriptionController::class, 'store'])
    ->middleware(['access:authenticated', 'throttle:20,1'])->name('settings.notifications.push.store');
Route::delete('/settings/notifications/push', [PushSubscriptionController::class, 'destroy'])
    ->middleware('access:authenticated')->name('settings.notifications.push.destroy');
Route::post('/settings/notifications/push/test', [PushSubscriptionController::class, 'test'])
    ->middleware(['access:authenticated', 'throttle:5,1'])->name('settings.notifications.push.test');
