<?php

return [
    'settings.notifications.gifts.update' => [
        'method' => 'PUT',
        'uri' => '/settings/notifications/gifts',
        'data' => ['lead_days' => [7, 1]],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
