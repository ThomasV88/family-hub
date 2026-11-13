<?php

$allUsers = fn (int $status) => [
    'guest' => 302, 'admin' => $status, 'parent' => $status, 'child' => $status,
    'grandparent' => $status, 'guestPreset' => $status, 'noGrants' => $status,
];

$subscription = [
    'endpoint' => 'https://fcm.googleapis.com/fcm/send/matrix-row',
    'keys' => [
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'auth' => 'tBHItJI5svbpez7KI4CCXg',
    ],
];

return [
    'settings.notifications.push.store' => ['method' => 'POST', 'uri' => '/settings/notifications/push', 'data' => $subscription, 'expect' => $allUsers(201)],
    'settings.notifications.push.destroy' => ['method' => 'DELETE', 'uri' => '/settings/notifications/push', 'data' => ['endpoint' => $subscription['endpoint']], 'expect' => $allUsers(204)],
    // Matrix users have no subscription of their own, so the test route answers 422.
    'settings.notifications.push.test' => ['method' => 'POST', 'uri' => '/settings/notifications/push/test', 'expect' => $allUsers(422)],
];
