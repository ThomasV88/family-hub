<?php

return [
    'finance.accounts.index' => [
        'method' => 'GET',
        'uri' => '/finance/accounts',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
