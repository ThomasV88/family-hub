<?php

use App\Models\MedicalMedication;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one medication of that person.
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $medication = MedicalMedication::factory()->create(['subject_id' => $params['person']]);

    return $params + ['medication' => (string) $medication->getKey()];
};
$data = ['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => 'Daily'];
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.medications.store' => ['method' => 'POST', 'uri' => '/health/people/{person}/medications', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.medications.update' => ['method' => 'PATCH', 'uri' => '/health/people/{person}/medications/{medication}', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.medications.destroy' => ['method' => 'DELETE', 'uri' => '/health/people/{person}/medications/{medication}', 'params' => $parent, 'expect' => $expect],
];
