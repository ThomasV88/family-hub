<?php

// Google sign-in: guests only. Without credentials configured (the test default) both routes send guests to /login.
$guestOnly = fn (int $guest) => [
    'guest' => $guest, 'admin' => 302, 'parent' => 302, 'child' => 302,
    'grandparent' => 302, 'guestPreset' => 302, 'noGrants' => 302,
];

return [
    'auth.google.redirect' => ['method' => 'GET', 'uri' => '/auth/google/redirect', 'expect' => $guestOnly(302)],
    'auth.google.callback' => ['method' => 'GET', 'uri' => '/auth/google/callback', 'expect' => $guestOnly(302)],
];
