<?php

use App\Models\MedicalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalPrivacy;

// Upload batch area (Phase 15; sorts after zz_ai.php). Per person one open batch with one pending item whose document title
// carries the canary only for the hidden persons, so a visible surface must stay canary-free. Ids are the public uuids.
$seed = function (string $subject, string $title): array {
    $user = (string) DB::table('medical_subject_members')->where('subject_id', $subject)->value('user_id') ?: null;
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $subject, 'created_by' => $user, 'acted_by' => $user,
        'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => $title, 'upload_batch_id' => $batch]);
    $item = (string) Str::uuid();
    DB::table('medical_upload_batch_items')->insert(['id' => $item, 'batch_id' => $batch, 'document_id' => $document->getKey(), 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return [$batch, $item];
};

return [
    'visible' => function (array &$ctx) use ($seed): void {
        [$ctx['batch'], $ctx['batch_item']] = $seed((string) $ctx['person']->getKey(), 'Visible batch document');
    },

    'hidden' => function (array &$ctx) use ($seed): void {
        [$ctx['hidden']['batch'], $ctx['hidden']['batch_item']] = $seed($ctx['hidden']['person'], MedicalPrivacy::CANARY.' hidden batch document');
    },

    'params' => [
        'batch' => fn (array $ctx): string => $ctx['batch'],
        'item' => fn (array $ctx): string => $ctx['batch_item'],
    ],

    'hiddenParams' => [
        'batch' => fn (array $ctx): string => $ctx['hidden']['batch'],
        'item' => fn (array $ctx): string => $ctx['hidden']['batch_item'],
    ],

    'payloads' => [
        'health.batches.store' => fn (array $ctx): array => ['acknowledged' => true, 'providers' => ['codex']],
        'health.batches.current' => fn (array $ctx): array => ['itemId' => $ctx['batch_item']],
        'health.batches.choices' => fn (array $ctx): array => ['choices' => ['medication-0' => ['decision' => 'reject']]],
        'health.batches.adjustments' => fn (array $ctx): array => ['brightness' => 110, 'contrast' => 100, 'bw' => false],
        'health.batches.rescan' => fn (array $ctx): array => ['acknowledged' => true, 'providers' => ['codex']],
        'health.batches.continue' => fn (array $ctx): array => [],
        'health.batches.items.destroy' => fn (array $ctx): array => [],
        'health.batches.destroy' => fn (array $ctx): array => [],
    ],
];
