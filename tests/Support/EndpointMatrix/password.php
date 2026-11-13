<?php

// Fortify password reset routes: guests may use them, signed-in members are redirected home.
$guestOnly = fn (int $guest) => [
    'guest' => $guest, 'admin' => 302, 'parent' => 302, 'child' => 302,
    'grandparent' => 302, 'guestPreset' => 302, 'noGrants' => 302,
];

return [
    'password.request' => ['method' => 'GET', 'uri' => '/forgot-password', 'expect' => $guestOnly(200)],
    'password.email' => ['method' => 'POST', 'uri' => '/forgot-password', 'data' => ['email' => 'nobody@example.test'], 'expect' => $guestOnly(302)],
    'password.reset' => ['method' => 'GET', 'uri' => '/reset-password/sometoken', 'expect' => $guestOnly(200)],
    'password.update' => ['method' => 'POST', 'uri' => '/reset-password', 'data' => [], 'expect' => $guestOnly(302)],
];
