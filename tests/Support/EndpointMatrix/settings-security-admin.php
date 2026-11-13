<?php

$adminOnly = fn (int $admin) => [
    'guest' => 302, 'admin' => $admin, 'parent' => 403, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    // The matrix runs on the default always-fresh seam; the stale 423 is proven in PolicyAndResetTest.
    'settings.security.policy' => [
        'method' => 'PUT',
        'uri' => '/settings/security/policy',
        'data' => ['required' => false],
        'expect' => $adminOnly(303),
    ],
    // The fixture child has no second factor, so the admin gets the scoped 422.
    'settings.members.second_factor.destroy' => [
        'method' => 'DELETE',
        'uri' => '/settings/members/{id}/second-factor',
        'params' => fn (array $users) => ['id' => $users['child']->id],
        'expect' => $adminOnly(422),
    ],
];
