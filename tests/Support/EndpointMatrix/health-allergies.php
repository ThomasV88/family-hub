<?php

use App\Models\MedicalAllergy;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one allergy of that person.
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $allergy = MedicalAllergy::factory()->create(['subject_id' => $params['person']]);

    return $params + ['allergy' => (string) $allergy->getKey()];
};
$data = ['substance' => 'Peanuts', 'reaction' => 'Hives', 'severity' => 'severe'];
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.allergies.store' => ['method' => 'POST', 'uri' => '/health/people/{person}/allergies', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.allergies.update' => ['method' => 'PATCH', 'uri' => '/health/people/{person}/allergies/{allergy}', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.allergies.destroy' => ['method' => 'DELETE', 'uri' => '/health/people/{person}/allergies/{allergy}', 'params' => $parent, 'expect' => $expect],
];
