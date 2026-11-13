<?php

return [
    'todo.index' => [
        'method' => 'GET',
        'uri' => '/todo',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403,
        ],
    ],
    'todo.tasks.store' => [
        'method' => 'POST',
        'uri' => '/todo/tasks',
        'data' => ['title' => 'Matrix task'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
