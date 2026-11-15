<?php

use App\Models\MedicalVisit;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one visit of that person.
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $visit = MedicalVisit::factory()->create(['subject_id' => $params['person']]);

    return $params + ['visit' => (string) $visit->getKey()];
};
$data = ['visited_on' => '2026-01-10', 'doctor' => 'Dr. Matrix', 'specialty_key' => 'cardiology'];

return [
    'health.visits.store' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/visits',
        'params' => $parent,
        'data' => $data,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.visits.update' => [
        'method' => 'PATCH',
        'uri' => '/health/people/{person}/visits/{visit}',
        'params' => $parent,
        'data' => $data,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.visits.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/visits/{visit}',
        'params' => $parent,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
