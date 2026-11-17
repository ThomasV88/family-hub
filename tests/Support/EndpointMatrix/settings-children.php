<?php

return [
    // Without a payload the admin reaches validation (302 back with errors, a non-Inertia POST); a stale admin
    // is answered by the access-granting proof. Real creation is covered in ChildCreateTest.
    'settings.members.children.store' => [
        'method' => 'POST',
        'uri' => '/settings/members/children',
        'expect' => [
            'guest' => 302, 'admin' => 302, 'parent' => 403, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],

    // Unknown target: the admin reaches the 404; a stale admin is answered by the access-granting proof.
    'settings.members.children.convert' => [
        'method' => 'POST',
        'uri' => '/settings/members/999999/convert',
        'data' => ['email' => 'matrix-convert@example.test', 'locale' => 'en'],
        'expect' => [
            'guest' => 302, 'admin' => 404, 'parent' => 403, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
