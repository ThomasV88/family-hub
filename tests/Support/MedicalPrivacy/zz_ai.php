<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use Tests\Support\MedicalPrivacy;

// AI area (sorts last: it needs the subjects area's people and the documents area's files). Per person one document
// with a ready proposal whose entries carry the canary only for the hidden person (a visible surface must stay canary-free) and a second document with a queued run. Payloads stay tiny.
$payload = fn (string $marker): array => [
    'schema_version' => 1,
    'document_date' => null,
    'specialty_key' => null,
    'visits' => [['visited_on' => '2026-01-05', 'doctor' => $marker.' ai doctor', 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => $marker.' ai diagnosis', 'notes' => null]],
    'medications' => [['name' => $marker.' ai medication', 'dose' => '5 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
    'allergies' => [],
    'vaccinations' => [],
];

$seed = function (string $subject, string $document, string $marker) use ($payload): array {
    // The documents area's file also gets a ready proposal, so the review route (which takes {document}) renders a page.
    MedicalAiExtraction::factory()->forDocument(MedicalDocument::query()->findOrFail($document))->ready($payload($marker), 2)->create();

    $ready = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Ai ready']);
    $queued = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Ai queued']);
    $extraction = MedicalAiExtraction::factory()->forDocument($ready)->ready($payload($marker), 2)->create();
    MedicalAiExtraction::factory()->forDocument($queued)->queued()->create();

    return [(string) $extraction->getKey(), (string) $queued->getKey(), (string) $ready->getKey()];
};

return [
    'visible' => function (array &$ctx) use ($seed): void {
        [$ctx['extraction'], $ctx['ai_queued_document'], $ctx['ai_ready_document']] = $seed((string) $ctx['person']->getKey(), (string) $ctx['document'], 'visible');
    },

    'hidden' => function (array &$ctx) use ($seed): void {
        [$ctx['hidden']['extraction'], $ctx['hidden']['ai_queued_document'], $ctx['hidden']['ai_ready_document']] = $seed($ctx['hidden']['person'], (string) $ctx['hidden']['document'], MedicalPrivacy::CANARY);
    },

    'params' => [
        'extraction' => fn (array $ctx): string => $ctx['extraction'],
        'entry' => fn (array $ctx): string => 'visit-0',
    ],

    'hiddenParams' => [
        'extraction' => fn (array $ctx): string => $ctx['hidden']['extraction'],
        'entry' => fn (array $ctx): string => 'visit-0',
    ],

    'payloads' => [
        'health.documents.ai.run' => fn (array $ctx): array => ['acknowledged' => true],
        'health.ai.cancel' => fn (array $ctx): array => [],
        'health.ai.entries.accept' => fn (array $ctx): array => [],
        'health.ai.entries.reject' => fn (array $ctx): array => [],
        'health.ai.entries.undo' => fn (array $ctx): array => [],
    ],
];
