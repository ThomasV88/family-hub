<?php

use App\Models\MedicalAllergy;

// Exports area: an unverified allergy on the visible subject A and panel variants of the export routes (scope,
// specialty, format, unverified). It seeds no hidden data on purpose: the hidden canary rows of the other area files
// (visits, allergies, medications, vaccinations, documents) are what the exports must never show.
return [
    'visible' => function (array &$ctx): void {
        MedicalAllergy::factory()->forSubject($ctx['person'])->create(['substance' => 'Visible unverified', 'source' => 'ai', 'verified_at' => null]);
    },

    'panels' => [
        'health.exports.print' => fn (array $ctx): array => [
            ['scope' => 'all', 'include_unverified' => '1'],
            ['scope' => 'specialty', 'specialty' => 'general_practice'],
        ],
        'health.exports.download' => fn (array $ctx): array => [
            ['scope' => 'all', 'format' => 'json'],
            ['scope' => 'all', 'format' => 'md', 'include_unverified' => '1'],
            ['scope' => 'specialty', 'specialty' => 'general_practice', 'format' => 'json'],
            ['scope' => 'specialty', 'specialty' => 'general_practice', 'format' => 'md'],
        ],
    ],
];
