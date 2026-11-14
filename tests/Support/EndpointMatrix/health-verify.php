<?php

use App\Models\MedicalAllergy;
use App\Models\MedicalMedication;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one AI-sourced unverified record of each type.
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $subject = ['subject_id' => $params['person'], 'source' => 'ai'];

    return $params + [
        'visit' => (string) MedicalVisit::factory()->create($subject)->getKey(),
        'medication' => (string) MedicalMedication::factory()->create($subject)->getKey(),
        'allergy' => (string) MedicalAllergy::factory()->create($subject)->getKey(),
        'vaccination' => (string) MedicalVaccination::factory()->create($subject)->public_id,
    ];
};
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.visits.verify' => ['method' => 'POST', 'uri' => '/health/people/{person}/visits/{visit}/verify', 'params' => $parent, 'expect' => $expect],
    'health.medications.verify' => ['method' => 'POST', 'uri' => '/health/people/{person}/medications/{medication}/verify', 'params' => $parent, 'expect' => $expect],
    'health.allergies.verify' => ['method' => 'POST', 'uri' => '/health/people/{person}/allergies/{allergy}/verify', 'params' => $parent, 'expect' => $expect],
    'health.vaccinations.verify' => ['method' => 'POST', 'uri' => '/health/people/{person}/vaccinations/{vaccination}/verify', 'params' => $parent, 'expect' => $expect],
];
