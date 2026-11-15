<?php

$signedIn = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];

return [
    'confirm-access' => ['method' => 'GET', 'uri' => '/confirm-access', 'expect' => $signedIn(200)],
    // Empty body: validation redirect for a signed-in member.
    'confirm-access.store' => ['method' => 'POST', 'uri' => '/confirm-access', 'data' => [], 'expect' => $signedIn(302)],
    'confirm-access.restart' => ['method' => 'POST', 'uri' => '/confirm-access/sign-in-again', 'expect' => $signedIn(303)],
];
