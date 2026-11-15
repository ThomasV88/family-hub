<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\MedicalMedication;
use App\Models\MedicalVisit;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

// Archive area (sorts after subjects.php, which creates $ctx['hidden']['person'] and ['own_off']). One archived visit of
// the visible subject A; archived canary records on the hidden subjects B and C and one archived hidden person D (co-member
// O only). The archived rows must leak nothing to the viewer: not in any page, not through the restore routes.
$old = fn () => now()->subDays(30);

return [
    'visible' => function (array &$ctx) use ($old): void {
        $ctx['archived_visit'] = (string) MedicalVisit::factory()->create([
            'subject_id' => $ctx['person']->getKey(),
            'doctor' => 'Dr Archived Visible',
            'created_at' => $old(),
            'archived_at' => now(),
            'archived_by' => $ctx['viewer']->id,
        ])->getKey();
    },

    'hidden' => function (array &$ctx) use ($old): void {
        $canary = fn (string $where): array => [
            'doctor' => MedicalPrivacy::CANARY." archived {$where} doctor",
            'diagnosis' => MedicalPrivacy::CANARY." archived {$where} diagnosis",
            'notes' => MedicalPrivacy::CANARY." archived {$where} notes",
            'created_at' => $old(),
            'archived_at' => now(),
        ];
        $ctx['hidden']['archived_visit'] = (string) MedicalVisit::factory()->create(['subject_id' => $ctx['hidden']['person']] + $canary('hidden'))->getKey();
        MedicalVisit::factory()->create(['subject_id' => $ctx['hidden']['own_off']] + $canary('own_off'));
        MedicalMedication::factory()->create([
            'subject_id' => $ctx['hidden']['person'], 'name' => MedicalPrivacy::CANARY.' archived medication', 'created_at' => $old(), 'archived_at' => now(),
        ]);
        MedicalDocument::factory()->create([
            'subject_id' => $ctx['hidden']['person'], 'title' => MedicalPrivacy::CANARY.' archived document', 'created_at' => $old(), 'archived_at' => now(),
        ]);
        $ctx['hidden']['archived_person'] = (string) MedicalFixtures::subject(
            ['name' => MedicalPrivacy::CANARY.' Archived person', 'archived_at' => now()],
            [[$ctx['other'], AccessLevel::Edit]],
        )->getKey();
    },

    'params' => [
        'type' => fn (array $ctx): string => 'visit',
        'record' => fn (array $ctx): string => $ctx['archived_visit'],
    ],

    'hiddenParams' => [
        'record' => fn (array $ctx): string => $ctx['hidden']['archived_visit'],
    ],
];
