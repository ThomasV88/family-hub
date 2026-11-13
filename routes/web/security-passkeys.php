<?php

use App\Http\Controllers\Security\ChallengePasskeyController;
use App\Http\Controllers\Security\PasskeyController;
use App\Http\Controllers\Security\ReauthPasskeyController;
use App\Http\Middleware\EnsurePasskeysEnabled;
use Illuminate\Support\Facades\Route;

// Always registered; EnsurePasskeysEnabled turns the MANAGEMENT routes into a 404 while SECURITY_PASSKEYS_ENABLED is off.
// The sign-in challenge and confirm-access passkey routes stay live for members who already have passkeys (WR-03).
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

// Second step of sign-in (the two-factor.passkey.* names get the challenge TTL guard).
Route::get('/two-factor-challenge/passkey/options', [ChallengePasskeyController::class, 'options'])
    ->middleware(['access:guest', 'throttle:two-factor'])->name('two-factor.passkey.options');
Route::post('/two-factor-challenge/passkey', [ChallengePasskeyController::class, 'store'])
    ->middleware(['access:guest', 'throttle:two-factor'])->name('two-factor.passkey.store');

// Confirm it's you.
Route::get('/confirm-access/passkey/options', [ReauthPasskeyController::class, 'options'])
    ->middleware(['access:authenticated', 'throttle:reauth'])->name('confirm-access.passkey.options');
Route::post('/confirm-access/passkey', [ReauthPasskeyController::class, 'store'])
    ->middleware(['access:authenticated', 'throttle:reauth'])->name('confirm-access.passkey.store');
