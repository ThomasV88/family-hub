<?php

return [
    // Target is the child; no payload means an empty set, which keeps rows independent. Non-Inertia PUT answers 302 for the admin.
    'settings.members.wishlist_managers.update' => [
        'method' => 'PUT',
        'uri' => '/settings/members/{id}/wishlist-managers',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'expect' => [
            'guest' => 302, 'admin' => 302, 'parent' => 403, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
