<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person with AI consent on and the notice acknowledged, and one open batch with one item
// that has a ready proposal (a valid visit entry). Create answers 200 with the existing batch; the real behaviour of every
// route is in UploadBatchHttpTest.
$withBatch = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    MedicalSubject::query()->whereKey($params['person'])->update(['ai_consent' => true]);
    DB::table('ai_notice_acks')->insertOrIgnore(['user_id' => $users['parent']->id]);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $params['person'], 'created_by' => $users['parent']->id, 'acted_by' => $users['parent']->id,
        'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $params['person'], 'upload_batch_id' => $batch]);
    $payload = [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => null, 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [], 'allergies' => [], 'vaccinations' => [],
    ];
    $extraction = MedicalAiExtraction::factory()->forDocument($document)->ready($payload, 1)->create(['started_by' => $users['parent']->id]);
    DB::table('medical_ai_extractions')->where('id', $extraction->getKey())->update(['upload_batch_id' => $batch]);
    $item = (string) Str::uuid();
    DB::table('medical_upload_batch_items')->insert([
        'id' => $item, 'batch_id' => $batch, 'document_id' => $document->getKey(), 'position' => 1, 'state' => 'sent',
        'extraction_id' => $extraction->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $params + ['batch' => $batch, 'item' => $item];
};
$expect = fn (int $parent) => [
    'guest' => 302, 'admin' => 403, 'parent' => $parent, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.batches.store' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/upload-batches',
        'params' => $withBatch,
        'data' => [],
        'expect' => $expect(200),
    ],
    'health.batches.show' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/upload-batches/{batch}',
        'params' => $withBatch,
        'expect' => $expect(200),
    ],
    'health.batches.status' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/upload-batches/{batch}/status',
        'params' => $withBatch,
        'expect' => $expect(200),
    ],
    'health.batches.current' => [
        'method' => 'PUT',
        'uri' => '/health/people/{person}/upload-batches/{batch}/current',
        'params' => $withBatch,
        'data' => fn (array $users, array $params) => ['itemId' => $params['item']],
        'expect' => $expect(204),
    ],
    'health.batches.choices' => [
        'method' => 'PUT',
        'uri' => '/health/people/{person}/upload-batches/{batch}/items/{item}/choices',
        'params' => $withBatch,
        'data' => ['choices' => ['visit-0' => ['decision' => 'reject']]],
        'expect' => $expect(200),
    ],
    'health.batches.adjustments' => [
        'method' => 'PUT',
        'uri' => '/health/people/{person}/upload-batches/{batch}/items/{item}/adjustments',
        'params' => $withBatch,
        'data' => ['brightness' => 110, 'contrast' => 100, 'bw' => false],
        'expect' => $expect(200),
    ],
    'health.batches.rescan' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/upload-batches/{batch}/items/{item}/rescan',
        'params' => $withBatch,
        'data' => ['acknowledged' => true, 'providers' => ['codex']],
        'expect' => $expect(202),
    ],
    'health.batches.continue' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/upload-batches/{batch}/items/{item}/continue',
        'params' => $withBatch,
        'data' => [],
        'expect' => $expect(200),
    ],
    'health.batches.items.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/upload-batches/{batch}/items/{item}',
        'params' => $withBatch,
        'expect' => $expect(204),
    ],
    'health.batches.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/upload-batches/{batch}',
        'params' => $withBatch,
        'expect' => $expect(200),
    ],
];
