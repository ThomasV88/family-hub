<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use Tests\Support\MedicalPrivacy;

// AI area (sorts last: it needs the subjects area's people and the documents area's files). Per person one document
// with a ready proposal whose entries carry the canary and a second document with a queued run. Payloads stay tiny.
$payload = fn (): array => [
    'schema_version' => 1,
    'document_date' => null,
    'specialty' => null,
    'entries' => [
        ['type' => 'visit', 'fields' => ['visited_on' => '2026-01-05', 'doctor' => MedicalPrivacy::CANARY.' ai doctor', 'diagnosis' => MedicalPrivacy::CANARY.' ai diagnosis']],
        ['type' => 'medication', 'fields' => ['name' => MedicalPrivacy::CANARY.' ai medication', 'dose' => '5 mg']],
    ],
];

$seed = function (string $subject) use ($payload): array {
    $ready = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Ai ready']);
    $queued = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Ai queued']);
    $extraction = MedicalAiExtraction::factory()->forDocument($ready)->ready($payload(), 2)->create();
    MedicalAiExtraction::factory()->forDocument($queued)->queued()->create();

    return [(string) $extraction->getKey(), (string) $queued->getKey()];
};

return [
    'visible' => function (array &$ctx) use ($seed): void {
        [$ctx['extraction'], $ctx['ai_queued_document']] = $seed((string) $ctx['person']->getKey());
    },

    'hidden' => function (array &$ctx) use ($seed): void {
        [$ctx['hidden']['extraction'], $ctx['hidden']['ai_queued_document']] = $seed($ctx['hidden']['person']);
    },

    'params' => [
        'extraction' => fn (array $ctx): string => $ctx['extraction'],
    ],

    'hiddenParams' => [
        'extraction' => fn (array $ctx): string => $ctx['hidden']['extraction'],
    ],

    'payloads' => [
        'health.documents.ai.run' => fn (array $ctx): array => ['acknowledged' => true],
        'health.ai.cancel' => fn (array $ctx): array => [],
    ],
];
