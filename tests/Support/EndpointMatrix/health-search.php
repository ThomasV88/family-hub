<?php

use Tests\Support\MedicalFixtures;

// The parent gets Health edit and a person; the search needs no id. A guest is redirected to login, everyone without
// Health view is refused before the controller runs. A query that matches nothing still answers 200 (empty items).
return [
    'health.search' => [
        'method' => 'POST',
        'uri' => '/health/search',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'data' => ['q' => 'matrix'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
