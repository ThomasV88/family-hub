<?php

use App\Http\Controllers\Security\PasskeyController;
use App\Http\Middleware\EnsurePasskeysEnabled;
use Illuminate\Support\Facades\Route;

// Always registered; EnsurePasskeysEnabled turns every route into a 404 while SECURITY_PASSKEYS_ENABLED is off.
// Features::passkeys() stays disabled: there is deliberately no passkey-only sign-in route.
$on = EnsurePasskeysEnabled::class;

// Management. Enrolment (options + store) and removal need the 10-minute confirmation; rename does not.
Route::get('/settings/security/passkeys/options', [PasskeyController::class, 'options'])
    ->middleware([$on, 'access:authenticated', 'access:recent', 'throttle:20,1'])->name('passkeys.options');
Route::post('/settings/security/passkeys', [PasskeyController::class, 'store'])
    ->middleware([$on, 'access:authenticated', 'access:recent', 'throttle:10,1'])->name('passkeys.store');
Route::patch('/settings/security/passkeys/{passkey}', [PasskeyController::class, 'update'])
    ->middleware([$on, 'access:authenticated', 'throttle:20,1'])->whereNumber('passkey')->name('passkeys.update');
Route::delete('/settings/security/passkeys/{passkey}', [PasskeyController::class, 'destroy'])
    ->middleware([$on, 'access:authenticated', 'access:recent', 'throttle:20,1'])->whereNumber('passkey')->name('passkeys.destroy');
