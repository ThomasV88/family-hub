<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Support\Facades\DB;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit and a person with AI consent on, one image document and the notice already acknowledged.
$withImage = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    MedicalSubject::query()->whereKey($params['person'])->update(['ai_consent' => true]);
    DB::table('ai_notice_acks')->insertOrIgnore(['user_id' => $users['parent']->id]);
    $document = MedicalDocument::factory()->image()->create(['subject_id' => $params['person']]);

    return $params + ['document' => (string) $document->getKey()];
};
$withQueued = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $params['person']]);
    $extraction = MedicalAiExtraction::factory()->forDocument($document)->queued()->create(['started_by' => $users['parent']->id]);

    return $params + ['extraction' => (string) $extraction->getKey()];
};
// A ready proposal with a valid visit entry and a medication entry, on a person the parent can edit.
$withReady = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $params['person']]);
    $payload = [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => null, 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [['name' => 'Matrix med', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
    $extraction = MedicalAiExtraction::factory()->forDocument($document)->ready($payload, 2)->create(['started_by' => $users['parent']->id]);

    return $params + ['document' => (string) $document->getKey(), 'extraction' => (string) $extraction->getKey(), 'entry' => 'visit-0'];
};
$expect = fn (int $parent) => [
    'guest' => 302, 'admin' => 403, 'parent' => $parent, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.documents.ai.run' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/documents/{document}/ai',
        'params' => $withImage,
        'data' => ['acknowledged' => true],
        'expect' => $expect(202),
    ],
    'health.ai.cancel' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/ai/{extraction}/cancel',
        'params' => $withQueued,
        'expect' => $expect(200),
    ],
    'health.people.ai.status' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/ai-status',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'expect' => $expect(200),
    ],
    'health.documents.ai.review' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/documents/{document}/review',
        'params' => $withReady,
        'expect' => $expect(200),
    ],
    'health.ai.entries.accept' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/ai/{extraction}/entries/{entry}/accept',
        'params' => $withReady,
        'data' => [],
        'expect' => $expect(200),
    ],
    'health.ai.entries.reject' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/ai/{extraction}/entries/{entry}/reject',
        'params' => $withReady,
        'data' => [],
        'expect' => $expect(200),
    ],
    'health.ai.entries.undo' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/ai/{extraction}/entries/{entry}/reject',
        'params' => $withReady,
        'data' => [],
        'expect' => $expect(200),
    ],
];
