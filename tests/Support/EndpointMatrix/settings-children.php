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
];
