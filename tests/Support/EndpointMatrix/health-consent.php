<?php

use Tests\Support\MedicalFixtures;

return [
    'health.people.ai_consent.update' => [
        'method' => 'PATCH',
        'uri' => '/health/people/{person}/ai-consent',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'data' => ['value' => true],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
