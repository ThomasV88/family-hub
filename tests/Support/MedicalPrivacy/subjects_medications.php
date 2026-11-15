<?php

use App\Models\MedicalMedication;
use Tests\Support\MedicalPrivacy;

// Medications area (file name sorts after subjects.php, which seeds the hidden subjects this area attaches rows to).
// One ongoing medication on the visible subject A; canary medications (every free-text column) on the hidden subject B
// and on the self-access-off subject C. The subjects area leaves their ids in $ctx['hidden'].

$canary = fn (string $where): array => [
    'name' => MedicalPrivacy::CANARY." {$where} name",
    'dose' => MedicalPrivacy::CANARY." {$where} dose",
    'schedule' => MedicalPrivacy::CANARY." {$where} schedule",
    'notes' => MedicalPrivacy::CANARY." {$where} notes",
];

return [
    'visible' => function (array &$ctx): void {
        $ctx['medication'] = (string) MedicalMedication::factory()->forSubject($ctx['person'])
            ->create(['name' => 'Visible medication', 'dose' => 'Visible dose', 'schedule' => 'Visible schedule'])->getKey();
    },

    'hidden' => function (array &$ctx) use ($canary): void {
        $ctx['hidden']['medication'] = (string) MedicalMedication::factory()->create(['subject_id' => $ctx['hidden']['person']] + $canary('hidden'))->getKey();
        $ctx['hidden']['medication_own_off'] = (string) MedicalMedication::factory()->create(['subject_id' => $ctx['hidden']['own_off']] + $canary('own_off'))->getKey();
    },

    'params' => [
        'medication' => fn (array $ctx): string => $ctx['medication'],
    ],

    'hiddenParams' => [
        'medication' => fn (array $ctx): string => $ctx['hidden']['medication'],
    ],

    'panels' => [
        'health.people.show' => fn (array $ctx): array => [
            ['panel' => 'medication', 'id' => $ctx['medication']],
            ['panel' => 'new-medication'],
        ],
    ],

    'payloads' => [
        'health.medications.store' => fn (array $ctx): array => ['name' => 'Parity'],
        'health.medications.update' => fn (array $ctx): array => ['name' => 'Parity'],
    ],
];
