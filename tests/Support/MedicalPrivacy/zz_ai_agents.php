<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use Tests\Support\MedicalPrivacy;

// AI agents area (sorts after zz_ai.php). Per person: one document with a ready Copilot-produced proposal (canary in the
// payload for the hidden person) and a newer waiting run on the same document, and a second document that only waits.
// The status and review routes must answer a member without access identically whether or not these exist.
$payload = fn (string $marker): array => [
    'schema_version' => 1,
    'document_date' => null,
    'specialty_key' => null,
    'visits' => [['visited_on' => '2026-01-06', 'doctor' => $marker.' copilot doctor', 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => $marker.' copilot diagnosis', 'notes' => null]],
    'medications' => [],
    'allergies' => [],
    'vaccinations' => [],
];

$seed = function (string $subject, string $marker) use ($payload): array {
    $both = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Agents copilot']);
    $waiting = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => 'Agents waiting']);
    MedicalAiExtraction::factory()->forDocument($both)->ready($payload($marker), 1)
        ->create(['agent' => 'copilot', 'model' => 'gpt-test-copilot', 'queued_at' => now()->subHour()]);
    MedicalAiExtraction::factory()->forDocument($both)->waiting(now()->addMinutes(45))->create(['queued_at' => now()->subMinute()]);
    MedicalAiExtraction::factory()->forDocument($waiting)->waiting(now()->addMinutes(45))->create();

    return [(string) $both->getKey(), (string) $waiting->getKey()];
};

return [
    'visible' => function (array &$ctx) use ($seed): void {
        [$ctx['ai_agents_copilot_document'], $ctx['ai_agents_waiting_document']] = $seed((string) $ctx['person']->getKey(), 'visible');
    },

    'hidden' => function (array &$ctx) use ($seed): void {
        [$ctx['hidden']['ai_agents_copilot_document'], $ctx['hidden']['ai_agents_waiting_document']] = $seed($ctx['hidden']['person'], MedicalPrivacy::CANARY);
    },
];
