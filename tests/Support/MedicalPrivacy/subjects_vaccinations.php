<?php

use App\Models\MedicalVaccination;
use Tests\Support\MedicalPrivacy;

// Vaccinations area (file name sorts after subjects.php, which seeds the hidden subjects this area attaches rows to).
// One vaccination with a due date on the visible subject A; canary vaccinations with overdue dates on the hidden
// subject B and on the self-access-off subject C, so a leaked attention count or list would differ. Record params are
// the PUBLIC uuids.

$canary = fn (string $where): array => [
    'vaccine' => MedicalPrivacy::CANARY." {$where} vaccine",
    'notes' => MedicalPrivacy::CANARY." {$where} notes",
    'given_on' => '2010-01-01',
    'next_due_on' => '2011-01-01',
];

return [
    'visible' => function (array &$ctx): void {
        $ctx['vaccination'] = (string) MedicalVaccination::factory()->forSubject($ctx['person'])
            ->create(['vaccine' => 'Visible vaccine', 'next_due_on' => '2099-01-01'])->public_id;
    },

    'hidden' => function (array &$ctx) use ($canary): void {
        $ctx['hidden']['vaccination'] = (string) MedicalVaccination::factory()->create(['subject_id' => $ctx['hidden']['person']] + $canary('hidden'))->public_id;
        $ctx['hidden']['vaccination_own_off'] = (string) MedicalVaccination::factory()->create(['subject_id' => $ctx['hidden']['own_off']] + $canary('own_off'))->public_id;
    },

    'params' => [
        'vaccination' => fn (array $ctx): string => $ctx['vaccination'],
    ],

    'hiddenParams' => [
        'vaccination' => fn (array $ctx): string => $ctx['hidden']['vaccination'],
    ],

    'panels' => [
        'health.people.show' => fn (array $ctx): array => [
            ['panel' => 'vaccination', 'id' => $ctx['vaccination']],
            ['panel' => 'new-vaccination'],
            ['view' => 'timeline', 'filter' => 'vaccinations'],
        ],
    ],

    'payloads' => [
        'health.vaccinations.store' => fn (array $ctx): array => ['vaccine' => 'Parity', 'given_on' => '2020-01-01'],
        'health.vaccinations.update' => fn (array $ctx): array => ['vaccine' => 'Parity', 'given_on' => '2020-01-01'],
        'health.vaccinations.given' => fn (array $ctx): array => ['given_on' => '2020-01-01'],
    ],
];
