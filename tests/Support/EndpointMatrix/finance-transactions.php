<?php

return [
    'finance.transactions.index' => [
        'method' => 'GET',
        'uri' => '/finance/transactions',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
