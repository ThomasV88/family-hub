<?php

use App\Models\MedicalVaccination;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one vaccination of that person (addressed by its public uuid).
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $vaccination = MedicalVaccination::factory()->create(['subject_id' => $params['person'], 'next_due_on' => '2099-01-01']);

    return $params + ['vaccination' => (string) $vaccination->public_id];
};
$data = ['vaccine' => 'Tetanus', 'given_on' => '2020-01-01', 'next_due_on' => '2030-01-01'];
$given = ['given_on' => '2020-06-01', 'next_due_on' => '2030-06-01'];
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.vaccinations.store' => ['method' => 'POST', 'uri' => '/health/people/{person}/vaccinations', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.vaccinations.update' => ['method' => 'PATCH', 'uri' => '/health/people/{person}/vaccinations/{vaccination}', 'params' => $parent, 'data' => $data, 'expect' => $expect],
    'health.vaccinations.destroy' => ['method' => 'DELETE', 'uri' => '/health/people/{person}/vaccinations/{vaccination}', 'params' => $parent, 'expect' => $expect],
    'health.vaccinations.given' => ['method' => 'POST', 'uri' => '/health/people/{person}/vaccinations/{vaccination}/given', 'params' => $parent, 'data' => $given, 'expect' => $expect],
];
