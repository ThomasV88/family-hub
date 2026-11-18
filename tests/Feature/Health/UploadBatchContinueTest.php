<?php

use App\Access\AccessLevel;
use App\Medical\Queries\UploadBatchQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('medical');
});

/** @return array<string, mixed> */
function ubContPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => 'Dr. A', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [
            ['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null],
            ['name' => 'Paracetamol', 'dose' => '500 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null],
        ],
        'allergies' => [['substance' => 'Pollen', 'reaction' => null, 'severity' => 'mild']],
        'vaccinations' => [],
    ];
}

/**
 * A batch of $n items; the first one has a ready proposal when $ready.
 *
 * @return array{user: User, person: MedicalSubject, viewer: Viewer, batch: string, items: list<string>, docs: list<string>, ext: ?string}
 */
function ubContScene(int $n = 2, bool $ready = true): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex', 'acknowledged_at' => now()]);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id,
        'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $items = [];
    $docs = [];
    $ext = null;
    for ($i = 1; $i <= $n; $i++) {
        $doc = MedicalDocument::factory()->image()->forSubject($person)->create(['upload_batch_id' => $batch]);
        Storage::disk('medical')->put($doc->path, 'bytes');
        Storage::disk('medical')->put($doc->thumb_path, 'thumb');
        $item = (string) Str::uuid();
        $row = ['id' => $item, 'batch_id' => $batch, 'document_id' => $doc->id, 'position' => $i, 'created_at' => now(), 'updated_at' => now()];
        if ($i === 1 && $ready) {
            $ext = (string) Str::uuid();
            DB::table('medical_ai_extractions')->insert([
                'id' => $ext, 'subject_id' => $person->id, 'document_id' => $doc->id, 'status' => 'ready', 'agent' => 'codex',
                'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $user->id, 'upload_batch_id' => $batch, 'queued_at' => now(),
                'payload' => json_encode(ubContPayload()), 'entry_count' => 4, 'model' => 'default', 'finished_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row += ['state' => 'sent', 'extraction_id' => $ext];
        }
        DB::table('medical_upload_batch_items')->insert($row);
        $items[] = $item;
        $docs[] = (string) $doc->id;
    }

    return ['user' => $user, 'person' => $person, 'viewer' => Viewer::for($user), 'batch' => $batch, 'items' => $items, 'docs' => $docs, 'ext' => $ext];
}

function ubContRecords(): int
{
    return DB::table('medical_visits')->count() + DB::table('medical_medications')->count() + DB::table('medical_allergies')->count();
}

function ubContChoices(array $s, array $choices): ?array
{
    return app(UploadBatchQuery::class)->saveChoices($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $choices);
}

it('stores only keys of the ready proposal with allowed fields, trimmed and cut, and shows them to another member', function () {
    $s = ubContScene();
    config(['medical.upload_batches.max_choice_value_chars' => 10]);

    $r = ubContChoices($s, [
        'medication-0' => ['decision' => 'approve', 'values' => ['name' => '  Ibuprofen forte extra long  ', 'doctor' => 'nope', 'dose' => 5]],
        'medication-1' => ['decision' => 'reject'],
        'medication-9' => ['decision' => 'approve'],
        'bogus' => ['decision' => 'approve'],
        'allergy-0' => ['decision' => 'maybe'],
    ]);

    expect($r['result'])->toBe('saved')
        ->and($r['choices'])->toBe([
            'medication-0' => ['decision' => 'approve', 'values' => ['name' => 'Ibuprofen', 'dose' => '5']],
            'medication-1' => ['decision' => 'reject'],
        ]);

    $other = User::factory()->create();
    MedicalFixtures::grantHealth($other, AccessLevel::Edit);
    DB::table('medical_subject_members')->insert(['subject_id' => $s['person']->id, 'user_id' => $other->id, 'level' => AccessLevel::Edit->value, 'created_at' => now()]);
    $page = app(UploadBatchQuery::class)->page(Viewer::for($other), $s['person'], $s['batch']);

    expect(json_decode(json_encode($page['current']['choices']), true))->toEqual($r['choices']);
});

it('refuses choices for an item without a ready proposal and for a done item', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);

    expect($q->saveChoices($s['viewer'], $s['person'], $s['batch'], $s['items'][1], ['medication-0' => ['decision' => 'reject']]))->toBe(['result' => 'not_ready']);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['state' => 'done']);
    expect(ubContChoices($s, ['medication-0' => ['decision' => 'reject']]))->toBe(['result' => 'not_ready'])
        ->and(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->value('choices'))->toBe('{}');
});

it('clamps and stores adjustments, returns them on the page and leaves the stored file alone', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    $path = (string) DB::table('medical_documents')->where('id', $s['docs'][0])->value('path');
    $before = Storage::disk('medical')->get($path);

    $stored = $q->saveAdjustments($s['viewer'], $s['person'], $s['batch'], $s['items'][0], ['brightness' => 500, 'contrast' => 10, 'bw' => 'true']);

    expect($stored)->toBe(['brightness' => 150, 'contrast' => 50, 'bw' => true])
        ->and($q->page($s['viewer'], $s['person'], $s['batch'])['current']['adjustments'])->toBe($stored)
        ->and(Storage::disk('medical')->get($path))->toBe($before);
});

it('requests a re-scan: clears choices, counts it, marks the request, cancels the active run and audits', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    ubContChoices($s, ['medication-0' => ['decision' => 'approve']]);
    // The document's current run is a queued one (as after an earlier re-scan): it is cancelled.
    $active = (string) Str::uuid();
    DB::table('medical_ai_extractions')->insert([
        'id' => $active, 'subject_id' => $s['person']->id, 'document_id' => $s['docs'][0], 'status' => 'queued', 'agent' => 'codex',
        'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $s['user']->id, 'upload_batch_id' => $s['batch'], 'queued_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['adjustments' => json_encode(['brightness' => 120, 'contrast' => 100, 'bw' => false])]);

    $r = $q->requestRescan($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user'], false);

    $item = DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->first();
    expect($r)->toBe(['result' => 'queued'])
        ->and($item->state)->toBe('pending')->and($item->choices)->toBe('{}')->and($item->rescan_count)->toBe(1)
        ->and($item->rescan_requested_at)->not->toBeNull()->and($item->requested_by)->toBe($s['user']->id)
        ->and(json_decode($item->adjustments, true)['brightness'])->toBe(120)
        ->and(DB::table('medical_ai_extractions')->where('id', $active)->value('status'))->toBe('cancelled')
        ->and(DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->whereJsonContains('meta->action', 'rescan')->count())->toBe(1);
});

it('refuses a re-scan at the cap, on a done item, without consent and without the notice', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    $rescan = fn () => $q->requestRescan($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user'], false);

    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_count' => 5]);
    expect($rescan()['result'])->toBe('limit');
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_count' => 4]);
    expect($rescan()['result'])->toBe('queued');
    expect($rescan()['result'])->toBe('limit');

    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['rescan_count' => 0, 'state' => 'done']);
    expect($rescan()['result'])->toBe('done');
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['state' => 'sent']);

    DB::table('medical_subjects')->where('id', $s['person']->id)->update(['ai_consent' => false]);
    expect($rescan()['result'])->toBe('consent_off');
    DB::table('medical_subjects')->where('id', $s['person']->id)->update(['ai_consent' => true]);

    DB::table('ai_notice_acks')->delete();
    $r = $rescan();
    expect($r['result'])->toBe('notice_required')->and($r['providers'])->toBe(['codex'])
        ->and($q->requestRescan($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user'], true, ['codex'], true)['result'])->toBe('queued');
});

it('continues: approves two (one edited), rejects one, leaves one undecided, makes an ordinary document and moves on', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    ubContChoices($s, [
        'medication-0' => ['decision' => 'approve', 'values' => ['dose' => '400 mg']],
        'allergy-0' => ['decision' => 'approve'],
        'medication-1' => ['decision' => 'reject'],
    ]);

    $r = $q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user']);

    expect($r)->toBe(['result' => 'continued', 'nextItemId' => $s['items'][1], 'approved' => 2, 'rejected' => 1])
        ->and(DB::table('medical_medications')->where('subject_id', $s['person']->id)->pluck('dose', 'name')->all())->toBe(['Ibuprofen' => '400 mg'])
        ->and(DB::table('medical_allergies')->where('subject_id', $s['person']->id)->value('substance'))->toBe('Pollen')
        ->and(DB::table('medical_ai_decisions')->where('extraction_id', $s['ext'])->orderBy('entry_key')->pluck('decision', 'entry_key')->all())
        ->toBe(['allergy-0' => 'accepted', 'medication-0' => 'accepted', 'medication-1' => 'rejected'])
        ->and(DB::table('medical_ai_decisions')->where('extraction_id', $s['ext'])->where('entry_key', 'medication-0')->value('edited'))->toBeTrue()
        ->and(DB::table('medical_documents')->where('id', $s['docs'][0])->value('upload_batch_id'))->toBeNull()
        ->and(DB::table('medical_documents')->where('id', $s['docs'][1])->value('upload_batch_id'))->toBe($s['batch'])
        ->and(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->value('state'))->toBe('done')
        ->and(DB::table('medical_upload_batches')->where('id', $s['batch'])->first()->current_item_id)->toBe($s['items'][1])
        ->and(DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->whereJsonContains('meta->action', 'continued')->count())->toBe(1);

    // visit-0 stayed undecided and reviewable on the ordinary review page.
    expect(DB::table('medical_ai_decisions')->where('extraction_id', $s['ext'])->where('entry_key', 'visit-0')->exists())->toBeFalse();

    $before = ubContRecords();
    $again = $q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user']);
    expect($again['result'])->toBe('continued')->and($again['approved'])->toBe(0)->and(ubContRecords())->toBe($before);
});

it('saves nothing when one approved finding fails validation and names the entry and field', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    ubContChoices($s, [
        'allergy-0' => ['decision' => 'approve'],
        'medication-0' => ['decision' => 'approve', 'values' => ['name' => '']],
        'medication-1' => ['decision' => 'reject'],
    ]);

    try {
        $q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user']);
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['entries.medication-0.name']);
    }

    expect(ubContRecords())->toBe(0)
        ->and(DB::table('medical_ai_decisions')->count())->toBe(0)
        ->and(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->value('state'))->toBe('sent')
        ->and(DB::table('medical_documents')->where('id', $s['docs'][0])->value('upload_batch_id'))->toBe($s['batch'])
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_entry_decided')->count())->toBe(0);
});

it('keeps a failed or never-read document without findings, and refuses while a run is active or a re-scan is pending', function () {
    $s = ubContScene(3, ready: false);
    $q = app(UploadBatchQuery::class);
    $run = function (string $docId, string $status, array $extra = []) use ($s) {
        $id = (string) Str::uuid();
        DB::table('medical_ai_extractions')->insert([
            'id' => $id, 'subject_id' => $s['person']->id, 'document_id' => $docId, 'status' => $status, 'agent' => 'codex',
            'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $s['user']->id, 'upload_batch_id' => $s['batch'], 'queued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra);

        return $id;
    };

    // Item 1: failed run. Item 2: queued run. Item 3: re-scan pending.
    $failed = $run($s['docs'][0], 'failed', ['failure_code' => 'unreadable_document', 'finished_at' => now()]);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update(['state' => 'sent', 'extraction_id' => $failed]);
    $queued = $run($s['docs'][1], 'queued');
    DB::table('medical_upload_batch_items')->where('id', $s['items'][1])->update(['state' => 'sent', 'extraction_id' => $queued]);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][2])->update(['rescan_requested_at' => now()]);

    expect($q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][1], $s['user'])['result'])->toBe('not_ready')
        ->and($q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][2], $s['user'])['result'])->toBe('not_ready');
    foreach (['running', 'waiting'] as $status) {
        DB::table('medical_ai_extractions')->where('id', $queued)->update($status === 'waiting' ? ['status' => 'waiting', 'available_at' => now()->addHour(), 'waiting_since' => now()] : ['status' => 'running', 'available_at' => null, 'started_at' => now()]);
        expect($q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][1], $s['user'])['result'])->toBe('not_ready');
    }

    $r = $q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user']);
    expect($r['result'])->toBe('continued')->and($r['approved'])->toBe(0)->and($r['nextItemId'])->toBe($s['items'][1])
        ->and(ubContRecords())->toBe(0)
        ->and(DB::table('medical_documents')->where('id', $s['docs'][0])->value('upload_batch_id'))->toBeNull()
        ->and(DB::table('medical_documents')->where('id', $s['docs'][0])->exists())->toBeTrue();
});

it('finishes the batch when the last unfinished item is continued', function () {
    $s = ubContScene(2, ready: false);
    $q = app(UploadBatchQuery::class);

    // A never-read document may be kept without findings.
    expect($q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user'])['result'])->toBe('continued');
    $r = $q->continue($s['viewer'], $s['person'], $s['batch'], $s['items'][1], $s['user']);

    $batch = DB::table('medical_upload_batches')->where('id', $s['batch'])->first();
    expect($r)->toBe(['result' => 'finished', 'nextItemId' => null, 'approved' => 0, 'rejected' => 0])
        ->and($batch->status)->toBe('finished')->and($batch->finished_at)->not->toBeNull()
        ->and($q->page($s['viewer'], $s['person'], $s['batch'])['batch']['currentItemId'])->toBeNull();
});

it('removes an unfinished item with its document and files, and refuses a done item', function () {
    $s = ubContScene();
    $q = app(UploadBatchQuery::class);
    $doc = DB::table('medical_documents')->where('id', $s['docs'][0])->first(['path', 'thumb_path']);
    DB::table('medical_upload_batches')->where('id', $s['batch'])->update(['current_item_id' => $s['items'][0]]);

    expect($q->removeItem($s['viewer'], $s['person'], $s['batch'], $s['items'][0], $s['user']))->toBeTrue()
        ->and(DB::table('medical_documents')->where('id', $s['docs'][0])->exists())->toBeFalse()
        ->and(DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->exists())->toBeFalse()
        ->and(DB::table('medical_ai_extractions')->where('id', $s['ext'])->exists())->toBeFalse()
        ->and(Storage::disk('medical')->exists($doc->path))->toBeFalse()
        ->and(Storage::disk('medical')->exists($doc->thumb_path))->toBeFalse()
        ->and(DB::table('medical_upload_batches')->where('id', $s['batch'])->value('current_item_id'))->toBeNull()
        ->and(DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->whereJsonContains('meta->action', 'removed')->count())->toBe(1);

    DB::table('medical_upload_batch_items')->where('id', $s['items'][1])->update(['state' => 'done']);
    expect($q->removeItem($s['viewer'], $s['person'], $s['batch'], $s['items'][1], $s['user']))->toBeFalse()
        ->and(DB::table('medical_documents')->where('id', $s['docs'][1])->exists())->toBeTrue();
});

it('deletes the batch through the purge and returns the number of removed documents', function () {
    $s = ubContScene(3);
    $q = app(UploadBatchQuery::class);

    expect($q->delete($s['viewer'], $s['person'], $s['batch'], $s['user']))->toBe(3)
        ->and(DB::table('medical_upload_batches')->count())->toBe(0)
        ->and(DB::table('medical_documents')->whereIn('id', $s['docs'])->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->whereJsonContains('meta->action', 'deleted')->count())->toBe(1);
});
