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
];
