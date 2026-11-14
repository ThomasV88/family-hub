<?php

use App\Access\AccessLevel;
use App\Models\MedicalContact;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

// Subjects area. Visible subject A comes from MedicalPrivacy::scenario(); the hidden seed adds B (co-member O only) and
// C (linked to the viewer, self access off, no membership), both carrying the canary.
return [
    'hidden' => function (array &$ctx): void {
        $ctx['hidden']['person'] = MedicalFixtures::subject(
            ['name' => MedicalPrivacy::CANARY.' Hidden'],
            [[$ctx['other'], AccessLevel::Edit]],
        )->getKey();
        $ctx['hidden']['own_off'] = MedicalFixtures::subject(
            ['name' => MedicalPrivacy::CANARY.' Own off', 'user_id' => $ctx['viewer']->id, 'self_access_enabled' => false],
        )->getKey();
        foreach (['person', 'own_off'] as $key) {
            MedicalContact::factory()->create([
                'subject_id' => $ctx['hidden'][$key],
                'name' => MedicalPrivacy::CANARY." Contact {$key}",
                'relation' => MedicalPrivacy::CANARY.' Relation',
                'phone' => '+421 777 777',
            ]);
        }
    },

    'params' => [
        'person' => fn (array $ctx): string => (string) $ctx['person']->getKey(),
    ],

    'hiddenParams' => [
        'person' => fn (array $ctx): string => (string) $ctx['hidden']['person'],
    ],

    'panels' => [
        'health.people.show' => fn (array $ctx): array => [['panel' => 'new-person']],
    ],

    'payloads' => [
        'health.people.store' => fn (array $ctx): array => ['name' => 'Parity Person'],
        'health.people.update' => fn (array $ctx): array => ['name' => 'Parity Person', 'contacts' => [['name' => 'Parity Contact', 'phone' => '123 456']]],
    ],
];
