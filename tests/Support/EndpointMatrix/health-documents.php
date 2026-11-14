<?php

use Tests\Support\MedicalFixtures;

return [
    // The matrix posts no file, so the Health editor reaches validation and is redirected back (302) with an error;
    // everyone else is refused with 403 before validation, like the wish image upload row. Real uploads: DocumentUploadTest.
    'health.documents.store' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/documents',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
