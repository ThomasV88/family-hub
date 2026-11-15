<?php

return [
    'settings.audit' => ['method' => 'GET', 'uri' => '/settings/audit', 'expect' => [
        'guest' => 302, 'admin' => 200, 'parent' => 403, 'child' => 403,
        'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
    ]],
];
