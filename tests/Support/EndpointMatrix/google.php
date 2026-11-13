<?php

// Google sign-in: guests only. Without credentials configured (the test default) both routes send guests to /login.
$guestOnly = fn (int $guest) => [
    'guest' => $guest, 'admin' => 302, 'parent' => 302, 'child' => 302,
    'grandparent' => 302, 'guestPreset' => 302, 'noGrants' => 302,
];

return [
    'auth.google.redirect' => ['method' => 'GET', 'uri' => '/auth/google/redirect', 'expect' => $guestOnly(302)],
    'auth.google.callback' => ['method' => 'GET', 'uri' => '/auth/google/callback', 'expect' => $guestOnly(302)],
    // Eligible only with the flag and a Google-only member; no fixture qualifies.
    'confirm-access.google' => ['method' => 'GET', 'uri' => '/confirm-access/google', 'expect' => [
        'guest' => 302, 'admin' => 404, 'parent' => 404, 'child' => 404,
        'grandparent' => 404, 'guestPreset' => 404, 'noGrants' => 404,
    ]],
];
