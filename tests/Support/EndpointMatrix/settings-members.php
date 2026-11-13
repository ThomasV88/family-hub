<?php

$adminOnly = fn (int $admin) => [
    'guest' => 302, 'admin' => $admin, 'parent' => 403, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'settings.members' => ['method' => 'GET', 'uri' => '/settings/members', 'expect' => $adminOnly(200)],
    // The target is the child; the payload keeps every module unchanged so rows stay independent.
    'settings.members.permissions.update' => [
        'method' => 'PUT',
        'uri' => '/settings/members/{id}/permissions',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'data' => ['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'none', 'health' => 'none'],
        'expect' => $adminOnly(302),
    ],
];
