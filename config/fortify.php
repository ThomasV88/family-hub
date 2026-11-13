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
    // must never be enabled here (invites are the only way in; Features::passkeys() would register
    // passwordless login, passkeys use their own routes). Two-factor runs headless: confirmPassword is
    // false because Fortify's password.confirm routes are denied; recency comes from the `recent` rule.
    'features' => [
        Features::resetPasswords(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => false, 'secret-length' => 32]),
    ],

];
