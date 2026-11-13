<?php

use App\Http\Controllers\Settings\InvitationsController;
use Illuminate\Support\Facades\Route;

// Numeric ids are resolved in the controller: route model binding runs before access:admin and would 404 non-admins.
Route::middleware(['access:admin', 'throttle:30,1'])->group(function () {
    Route::post('/settings/invitations', [InvitationsController::class, 'store'])->name('settings.invitations.store');
    Route::post('/settings/invitations/{invitation}/resend', [InvitationsController::class, 'resend'])
        ->whereNumber('invitation')->name('settings.invitations.resend');
    Route::post('/settings/invitations/{invitation}/link', [InvitationsController::class, 'link'])
        ->whereNumber('invitation')->name('settings.invitations.link');
    Route::delete('/settings/invitations/{invitation}', [InvitationsController::class, 'destroy'])
        ->whereNumber('invitation')->name('settings.invitations.destroy');
});
