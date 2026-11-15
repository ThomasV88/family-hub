<?php

// Invite acceptance: guests only. An unknown token renders the invalid state (200), signed-in members go home.
$guestOnly = fn (int $guest) => [
    'guest' => $guest, 'admin' => 302, 'parent' => 302, 'child' => 302,
    'grandparent' => 302, 'guestPreset' => 302, 'noGrants' => 302,
];

return [
    'invite.show' => ['method' => 'GET', 'uri' => '/invite/matrixunknowntoken', 'expect' => $guestOnly(200)],
    'invite.accept' => [
        'method' => 'POST',
        'uri' => '/invite/matrixunknowntoken',
        'data' => ['name' => 'Matrix', 'password' => 'a-long-matrix-passphrase', 'password_confirmation' => 'a-long-matrix-passphrase'],
        'expect' => $guestOnly(200),
    ],
];
