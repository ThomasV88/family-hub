<?php

use Tests\Support\MedicalFixtures;

$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.emergency.show' => [
        'method' => 'GET', 'uri' => '/health/people/{person}/emergency-card',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'expect' => $expect,
    ],
];
