<?php

use App\Models\MedicalVisit;
use Tests\Support\MedicalPrivacy;

// Visits area. One visit on the visible subject A; canary visits (every free-text column) on the hidden subject B and on
// the self-access-off subject C. The subjects area runs first (sorted file order) and leaves their ids in $ctx['hidden'].
$canary = fn (string $where): array => [
    'doctor' => MedicalPrivacy::CANARY." {$where} doctor",
    'diagnosis' => MedicalPrivacy::CANARY." {$where} diagnosis",
    'notes' => MedicalPrivacy::CANARY." {$where} notes",
];

return [
    'visible' => function (array &$ctx): void {
        $ctx['visit'] = (string) MedicalVisit::factory()->forSubject($ctx['person'])->specialty('cardiology')
            ->create(['doctor' => 'Dr Visible', 'diagnosis' => 'Visible diagnosis', 'notes' => 'Visible notes'])->getKey();
    },

    'hidden' => function (array &$ctx) use ($canary): void {
        $ctx['hidden']['visit'] = (string) MedicalVisit::factory()->create(
            ['subject_id' => $ctx['hidden']['person'], 'specialty_key' => 'other', 'specialty_other' => MedicalPrivacy::CANARY.' specialty'] + $canary('hidden'),
        )->getKey();
        $ctx['hidden']['visit_own_off'] = (string) MedicalVisit::factory()->create(
            ['subject_id' => $ctx['hidden']['own_off'], 'specialty_key' => 'neurology'] + $canary('own_off'),
        )->getKey();
    },

    'params' => [
        'visit' => fn (array $ctx): string => $ctx['visit'],
    ],

    'hiddenParams' => [
        'visit' => fn (array $ctx): string => $ctx['hidden']['visit'],
    ],

    'panels' => [
        'health.people.show' => fn (array $ctx): array => [
            ['panel' => 'visit', 'id' => $ctx['visit']],
            ['view' => 'timeline'],
            ['view' => 'timeline', 'filter' => 'cardiology'],
        ],
    ],

    'payloads' => [
        'health.visits.store' => fn (array $ctx): array => ['visited_on' => '2026-01-10', 'specialty_key' => 'cardiology', 'doctor' => 'Parity'],
        'health.visits.update' => fn (array $ctx): array => ['visited_on' => '2026-01-10', 'specialty_key' => 'cardiology', 'doctor' => 'Parity'],
    ],
];
