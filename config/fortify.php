<?php

use Laravel\Fortify\Features;

return [

    'guard' => 'web',

    'home' => '/',

    'lowercase_usernames' => true,

    // Named limiter 'login' is defined in AppServiceProvider (5/min per email+IP, 10/15min per email, 30/min per IP).
    'limiters' => [
        'login' => 'login',
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

    // Phase 2 policy: registration, emailVerification, updateProfileInformation,
    // updatePasswords, twoFactorAuthentication and passkeys must never be enabled here
    // (invites are the only way in; 2FA and passkeys are Phase 6). resetPasswords is
    // enabled in plan 04 together with its views.
    'features' => [
        Features::resetPasswords(),
    ],

];
