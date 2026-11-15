<?php

use App\Access\Preset;
use App\Models\Invitation;

$adminOnly = fn (int $admin) => [
    'guest' => 302, 'admin' => $admin, 'parent' => 403, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

$pending = fn (array $users) => ['id' => Invitation::create([
    'email' => 'matrix-'.uniqid().'@example.test',
    'token_hash' => hash('sha256', uniqid('', true)),
    'preset' => Preset::Child->value,
    'locale' => 'en',
    'expires_at' => now()->addDays(7),
])->id];

return [
    'settings.invitations.store' => [
        'method' => 'POST',
        'uri' => '/settings/invitations',
        'data' => ['email' => 'matrix-new@example.test', 'preset' => 'child', 'locale' => 'en'],
        'expect' => $adminOnly(303),
    ],
    'settings.invitations.resend' => [
        'method' => 'POST', 'uri' => '/settings/invitations/{id}/resend', 'params' => $pending, 'expect' => $adminOnly(303),
    ],
    'settings.invitations.link' => [
        'method' => 'POST', 'uri' => '/settings/invitations/{id}/link', 'params' => $pending, 'expect' => $adminOnly(303),
    ],
    'settings.invitations.destroy' => [
        'method' => 'DELETE', 'uri' => '/settings/invitations/{id}', 'params' => $pending, 'expect' => $adminOnly(303),
    ],
];
