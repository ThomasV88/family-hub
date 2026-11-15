<?php

$adminOnly = fn (int $admin) => [
    'guest' => 302, 'admin' => $admin, 'parent' => 403, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'settings.members.sessions.index' => [
        'method' => 'GET',
        'uri' => '/settings/members/{id}/sessions',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'expect' => $adminOnly(200),
    ],
    // No such session row exists in the matrix fixture, so the admin gets the scoped 404.
    'settings.members.sessions.destroy' => [
        'method' => 'DELETE',
        'uri' => '/settings/members/{id}/sessions/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'expect' => $adminOnly(404),
    ],
    'settings.members.sessions.destroy_all' => [
        'method' => 'DELETE',
        'uri' => '/settings/members/{id}/sessions',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'expect' => array_merge($adminOnly(303), ['guest' => 302]),
    ],
];
