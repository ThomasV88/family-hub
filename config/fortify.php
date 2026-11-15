<?php

use Laravel\Fortify\Features;

return [

    'guard' => 'web',

    'home' => '/',

    'lowercase_usernames' => true,

    // Named limiter 'login' is defined in AppServiceProvider (5/min per email+IP, 10/15min per email, 30/min per IP).
    // Named limiter 'two-factor' (SecurityServiceProvider) is keyed by the pending login id, not the session.
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    // Policy: registration, emailVerification, updateProfileInformation, updatePasswords and passkeys
    // must never be enabled here (invites are the only way in; the passkeys feature would register
    // passwordless login, passkeys use their own routes). Two-factor runs headless: confirmPassword is
    // false because Fortify's password.confirm routes are denied; recency comes from the `recent` rule.
    'features' => [
        Features::resetPasswords(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => false, 'secret-length' => 32]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkeys (relying party)
    |--------------------------------------------------------------------------
    |
    | Read by Fortify's service provider to configure laravel/passkeys, even though the passkeys feature stays
    | off. Unset values are dropped so Fortify's defaults apply (rp id and origin from APP_URL, user handle secret
    | from APP_KEY) instead of an explicit null. Each environment binds passkeys to its own domain.
    |
    */
    'passkeys' => array_filter([
        'relying_party_id' => env('PASSKEYS_RP_ID'),
        'allowed_origins' => env('PASSKEYS_ALLOWED_ORIGINS')
            ? array_values(array_filter(array_map('trim', explode(',', (string) env('PASSKEYS_ALLOWED_ORIGINS')))))
            : null,
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET'),
    ], fn ($value) => $value !== null && $value !== '' && $value !== []),

];
