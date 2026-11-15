<?php

$signedIn = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];

return [
    'settings.security' => ['method' => 'GET', 'uri' => '/settings/security', 'expect' => $signedIn(200)],
    'settings.security.continue' => ['method' => 'POST', 'uri' => '/settings/security/continue', 'expect' => $signedIn(303)],
];
