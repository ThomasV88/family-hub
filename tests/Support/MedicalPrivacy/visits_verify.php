<?php

use App\Models\MedicalAllergy;
use App\Models\MedicalMedication;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use Tests\Support\MedicalPrivacy;

// Verify routes. The record params come from visits.php and the subjects_* areas (sorted first); this area adds one
// AI-sourced unverified record of each type to the visible subject and canary ones to the hidden subject, so the
// pages render unverified rows and a leaked label or count would differ.
return [
    'visible' => function (array &$ctx): void {
        $ai = ['subject_id' => (string) $ctx['person']->getKey(), 'source' => 'ai'];
        MedicalVisit::factory()->create($ai);
        MedicalMedication::factory()->create($ai);
        MedicalAllergy::factory()->create($ai);
        MedicalVaccination::factory()->create($ai);
    },

    'hidden' => function (array &$ctx): void {
        $ai = ['subject_id' => $ctx['hidden']['person'], 'source' => 'ai'];
        MedicalVisit::factory()->create($ai + ['doctor' => MedicalPrivacy::CANARY.' ai doctor']);
        MedicalMedication::factory()->create($ai + ['name' => MedicalPrivacy::CANARY.' ai medication']);
        MedicalAllergy::factory()->create($ai + ['substance' => MedicalPrivacy::CANARY.' ai substance']);
        MedicalVaccination::factory()->create($ai + ['vaccine' => MedicalPrivacy::CANARY.' ai vaccine']);
    },

    'payloads' => [
        'health.visits.verify' => fn (array $ctx): array => [],
        'health.medications.verify' => fn (array $ctx): array => [],
        'health.allergies.verify' => fn (array $ctx): array => [],
        'health.vaccinations.verify' => fn (array $ctx): array => [],
    ],
];
