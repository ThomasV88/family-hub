<?php

use App\Models\MedicalDocument;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalPrivacy;

// Search and recognised text area (named zz_ so it sorts after the subjects and documents areas whose people and
// documents it needs). The visible person's document gets plain text; every hidden person's document gets text that
// carries the canary, so any surface, search answer or log that prints it fails the net. Text is stored through the real
// store (DocumentTextFixtures), never from an AI run.
return [
    'visible' => function (array &$ctx): void {
        DocumentTextFixtures::save(MedicalDocument::query()->findOrFail($ctx['document']), ['Visible page one paracetamol', 'Visible page two']);
    },

    'hidden' => function (array &$ctx): void {
        foreach (['document', 'document_own_off', 'document_image', 'document_image_own_off'] as $key) {
            if (isset($ctx['hidden'][$key])) {
                DocumentTextFixtures::save(MedicalDocument::query()->findOrFail($ctx['hidden'][$key]), ['Dr '.MedicalPrivacy::CANARY.' hidden recognised text '.MedicalPrivacy::CANARY, 'second hidden page']);
            }
        }
    },

    'payloads' => [
        // A query that matches the hidden canary: the answer must read like a family without that document.
        'health.search' => fn (array $ctx): array => ['q' => MedicalPrivacy::CANARY],
    ],
];
