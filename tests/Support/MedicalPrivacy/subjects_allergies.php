<?php

use App\Models\MedicalAllergy;
use Tests\Support\MedicalPrivacy;

// Allergies area (file name sorts after subjects.php, which seeds the hidden subjects this area attaches rows to). One
// allergy on the visible subject A; canary allergies (every free-text column) on the hidden subject B and on the
// self-access-off subject C. The subjects area leaves their ids in $ctx['hidden'].

$canary = fn (string $where): array => [
    'substance' => MedicalPrivacy::CANARY." {$where} substance",
    'reaction' => MedicalPrivacy::CANARY." {$where} reaction",
    'severity' => 'severe',
];

return [
    'visible' => function (array &$ctx): void {
        $ctx['allergy'] = (string) MedicalAllergy::factory()->forSubject($ctx['person'])
            ->create(['substance' => 'Visible substance', 'reaction' => 'Visible reaction'])->getKey();
    },

    'hidden' => function (array &$ctx) use ($canary): void {
        $ctx['hidden']['allergy'] = (string) MedicalAllergy::factory()->create(['subject_id' => $ctx['hidden']['person']] + $canary('hidden'))->getKey();
        $ctx['hidden']['allergy_own_off'] = (string) MedicalAllergy::factory()->create(['subject_id' => $ctx['hidden']['own_off']] + $canary('own_off'))->getKey();
    },

    'params' => [
        'allergy' => fn (array $ctx): string => $ctx['allergy'],
    ],

    'hiddenParams' => [
        'allergy' => fn (array $ctx): string => $ctx['hidden']['allergy'],
    ],

    'panels' => [
        'health.people.show' => fn (array $ctx): array => [
            ['panel' => 'allergy', 'id' => $ctx['allergy']],
            ['panel' => 'new-allergy'],
        ],
    ],

    'payloads' => [
        'health.allergies.store' => fn (array $ctx): array => ['substance' => 'Parity', 'severity' => 'mild'],
        'health.allergies.update' => fn (array $ctx): array => ['substance' => 'Parity', 'severity' => 'mild'],
    ],
];
