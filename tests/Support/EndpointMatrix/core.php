<?php

// Phase 1 and Fortify routes. Statuses are written by hand (302 redirect, 303 redirect after write, 403 denied).
$signedIn = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];
$everyone = fn (int $status) => array_fill_keys(
    ['guest', 'admin', 'parent', 'child', 'grandparent', 'guestPreset', 'noGrants'],
    $status,
);

return [
    'home' => ['method' => 'GET', 'uri' => '/', 'expect' => $signedIn(200)],
    'heartbeat.store' => ['method' => 'POST', 'uri' => '/heartbeat', 'expect' => $signedIn(303)],
    'locale.update' => ['method' => 'POST', 'uri' => '/locale', 'data' => ['locale' => 'sk'], 'expect' => $everyone(303)],
    'style' => ['method' => 'GET', 'uri' => '/_style', 'expect' => $everyone(200)],
    'login' => ['method' => 'GET', 'uri' => '/login', 'expect' => ['guest' => 200] + $signedIn(302)],
    'login.store' => ['method' => 'POST', 'uri' => '/login', 'data' => [], 'expect' => $everyone(302)],
    'logout' => ['method' => 'POST', 'uri' => '/logout', 'expect' => $signedIn(303)],
    'password.confirm' => ['method' => 'GET', 'uri' => '/user/confirm-password', 'expect' => $signedIn(404)],
    'password.confirm.store' => ['method' => 'POST', 'uri' => '/user/confirm-password', 'data' => [], 'expect' => $signedIn(302)],
    'password.confirmation' => ['method' => 'GET', 'uri' => '/user/confirmed-password-status', 'expect' => $signedIn(200)],
];
