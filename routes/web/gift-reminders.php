<?php

use App\Http\Controllers\Settings\GiftReminderSettingsController;
use Illuminate\Support\Facades\Route;

Route::put('/settings/notifications/gifts', [GiftReminderSettingsController::class, 'update'])
    ->middleware(['access:module,wishlist,edit', 'throttle:30,1,settings.notifications.gifts.update'])->name('settings.notifications.gifts.update');
