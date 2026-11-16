<?php

$nonAdmins = ['parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'settings.help' => [
        'method' => 'GET',
        'uri' => '/settings/help',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.help.show' => [
        'method' => 'GET',
        'uri' => '/settings/help/tasks.visibility',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.help.update' => [
        'method' => 'PUT',
        'uri' => '/settings/help/tasks.visibility',
        'data' => ['texts' => ['en' => 'Who may see this task.']],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
];
