<?php

use App\Access\AccessLevel;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Medical\Batches\ItemState;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Queries\UploadBatchQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject, 2: Viewer} an Edit member and a consenting person */
function ubQScene(AccessLevel $level = AccessLevel::Edit, bool $consent = true): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => $consent, 'name' => 'Batch Person'], [[$user, $level]]);

    return [$user, $person, Viewer::for($user)];
}

function ubQAck(User $user): void
{
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex', 'acknowledged_at' => now()]);
}

/** @return array<string, mixed> a document store put() result */
function ubQStored(string $sha = 'a'): array
{
    $sha = str_repeat($sha, 64);

    return [
        'kind' => 'image', 'mime' => 'image/jpeg', 'size_bytes' => 100, 'sha256' => $sha,
        'path' => 'ub/'.Str::uuid().'.jpg', 'thumb_path' => 'ub/'.Str::uuid().'.thumb.jpg', 'width' => 10, 'height' => 10,
    ];
}

function ubQCreate(User $user, MedicalSubject $person): string
{
    ubQAck($user);
    $r = app(UploadBatchQuery::class)->create(Viewer::for($user), $person, $user, false);
    expect($r['result'])->toBe('created');

    return $r['batchId'];
}

it('refuses to create for a View member, a hidden person and a person without membership', function () {
    [$user, $person] = ubQScene(AccessLevel::View);
    $stranger = MedicalFixtures::subject(['ai_consent' => true]);
    $q = app(UploadBatchQuery::class);

    expect($q->create(Viewer::for($user), $person, $user, true, ['codex'], true)['result'])->toBe('denied')
        ->and($q->create(Viewer::for($user), $stranger, $user, true, ['codex'], true)['result'])->toBe('denied')
        ->and(DB::table('medical_upload_batches')->count())->toBe(0);
});

it('answers consent_off, unavailable and notice_required before creating anything', function () {
    [$user, $person, $viewer] = ubQScene(consent: false);
    $q = app(UploadBatchQuery::class);
    expect($q->create($viewer, $person, $user, true, ['codex'], true)['result'])->toBe('consent_off');

    DB::table('medical_subjects')->where('id', $person->id)->update(['ai_consent' => true]);
    app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedOut);
    expect($q->create($viewer, $person, $user, true, ['codex'], true)['result'])->toBe('unavailable');

    app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedIn);
    config(['ai.ocr.enabled' => true]);
    $r = $q->create($viewer, $person, $user, false);
    expect($r['result'])->toBe('notice_required')->and($r['providers'])->toBe(['codex']);
    // Acknowledged without the text statement while recognised text is stored: still required.
    $r = $q->create($viewer, $person, $user, true, ['codex'], false);
    expect($r['result'])->toBe('notice_required')
        ->and(DB::table('medical_upload_batches')->count())->toBe(0)
        ->and(DB::table('ai_notice_acks')->count())->toBe(0);
});

it('creates a batch with expiry, acknowledgment and one audit row, and returns the open one afterwards', function () {
    [$user, $person, $viewer] = ubQScene();
    $q = app(UploadBatchQuery::class);

    $r = $q->create($viewer, $person, $user, true, ['codex'], false);
    $row = DB::table('medical_upload_batches')->where('id', $r['batchId'])->first();

    expect($r['result'])->toBe('created')
        ->and($row->status)->toBe('open')->and($row->created_by)->toBe($user->id)->and($row->acted_by)->toBe($user->id)
        ->and(abs(Carbon::parse($row->expires_at)->diffInDays(now(), true) - 30))->toBeLessThan(1)
        ->and(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('providers'))->toBe('codex')
        ->and(DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->count())->toBe(1);

    $again = $q->create($viewer, $person, $user, false);
    expect($again)->toBe(['result' => 'exists', 'batchId' => $r['batchId']])
        ->and(DB::table('medical_upload_batches')->count())->toBe(1);
});

it('adds documents with positions, refuses duplicates, a full batch and a closed batch', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);

    $a = $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'First', $user);
    $b = $q->addDocument($viewer, $person, $batch, ubQStored('b'), 'Second', $user);
    expect(DB::table('medical_upload_batch_items')->where('id', $a['itemId'])->value('position'))->toBe(1)
        ->and(DB::table('medical_upload_batch_items')->where('id', $b['itemId'])->value('position'))->toBe(2)
        ->and(DB::table('medical_upload_batch_items')->where('id', $a['itemId'])->value('state'))->toBe('pending')
        ->and(DB::table('medical_documents')->where('id', $a['documentId'])->value('upload_batch_id'))->toBe($batch)
        ->and($a['duplicateOf'])->toBeNull();

    expect(fn () => $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'Again', $user))->toThrow(DocumentRejected::class, 'duplicate');

    config(['medical.upload_batches.max_files' => 2]);
    expect(fn () => $q->addDocument($viewer, $person, $batch, ubQStored('c'), 'Third', $user))->toThrow(DocumentRejected::class, 'batch_full');
    config(['medical.upload_batches.max_files' => 50]);

    config(['medical.max_documents_per_subject' => 2]);
    expect(fn () => $q->addDocument($viewer, $person, $batch, ubQStored('d'), 'Over cap', $user))->toThrow(DocumentRejected::class, 'limit_reached');
    config(['medical.max_documents_per_subject' => 200]);

    DB::table('medical_upload_batches')->where('id', $batch)->update(['status' => 'finished', 'finished_at' => now()]);
    expect(fn () => $q->addDocument($viewer, $person, $batch, ubQStored('e'), 'Late', $user))->toThrow(DocumentRejected::class, 'batch_closed')
        ->and(DB::table('medical_upload_batch_items')->count())->toBe(2);
});

it('marks a file the person already has as an ordinary document', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $existing = MedicalDocument::factory()->image()->forSubject($person)->create(['sha256' => str_repeat('f', 64)]);

    $added = app(UploadBatchQuery::class)->addDocument($viewer, $person, $batch, ubQStored('f'), 'Same file', $user);

    expect($added['duplicateOf'])->toBe((string) $existing->id)
        ->and(DB::table('medical_upload_batch_items')->where('id', $added['itemId'])->value('duplicate_of'))->toBe((string) $existing->id);
});

it('answers null everywhere for another person, a hidden person, a malformed id and an unknown id', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);
    $item = $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'First', $user)['itemId'];

    $other = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $hidden = MedicalFixtures::subject(['ai_consent' => true]);
    $hiddenBatch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert(['id' => $hiddenBatch, 'subject_id' => $hidden->id, 'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
    $unknown = (string) Str::uuid();

    $calls = [
        'page' => fn ($p, $b) => $q->page($viewer, $p, $b),
        'status' => fn ($p, $b) => $q->status($viewer, $p, $b),
        'addDocument' => fn ($p, $b) => $q->addDocument($viewer, $p, $b, ubQStored('z'), 'x', $user),
        'setCurrent' => fn ($p, $b) => $q->setCurrent($viewer, $p, $b, $item),
        'saveChoices' => fn ($p, $b) => $q->saveChoices($viewer, $p, $b, $item, []),
        'saveAdjustments' => fn ($p, $b) => $q->saveAdjustments($viewer, $p, $b, $item, []),
        'requestRescan' => fn ($p, $b) => $q->requestRescan($viewer, $p, $b, $item, $user, true, ['codex'], true),
        'continue' => fn ($p, $b) => $q->continue($viewer, $p, $b, $item, $user),
        'removeItem' => fn ($p, $b) => $q->removeItem($viewer, $p, $b, $item, $user),
        'delete' => fn ($p, $b) => $q->delete($viewer, $p, $b, $user),
    ];
    foreach ($calls as $name => $call) {
        expect($call($other, $batch))->toBeNull("$name: batch of another person")
            ->and($call($hidden, $hiddenBatch))->toBeNull("$name: hidden person")
            ->and($call($person, 'not-a-uuid'))->toBeNull("$name: malformed id")
            ->and($call($person, $unknown))->toBeNull("$name: unknown id");
    }
    expect(DB::table('medical_upload_batches')->count())->toBe(2)->and(DB::table('medical_upload_batch_items')->count())->toBe(1);

    // A View-only member of the same person is out of scope too.
    $viewOnly = User::factory()->create();
    MedicalFixtures::grantHealth($viewOnly, AccessLevel::Edit);
    DB::table('medical_subject_members')->insert(['subject_id' => $person->id, 'user_id' => $viewOnly->id, 'level' => AccessLevel::View->value, 'created_at' => now()]);
    expect($q->page(Viewer::for($viewOnly), $person, $batch))->toBeNull();
});

it('returns the full server-side state on page and a poll payload with the same items', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);
    $first = $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'First', $user);
    $q->addDocument($viewer, $person, $batch, ubQStored('b'), 'Second', $user);
    DB::table('medical_upload_batch_items')->where('id', $first['itemId'])->update(['state' => 'done']);

    $page = $q->page($viewer, $person, $batch);
    $status = $q->status($viewer, $person, $batch);

    expect(array_keys($page))->toBe(['batch', 'items', 'current', 'limits', 'ai', 'person'])
        ->and($page['batch']['status'])->toBe('open')
        ->and($page['batch']['counts'])->toBe(['total' => 2, 'done' => 1, 'review' => 0, 'processing' => 1, 'failed' => 0])
        ->and($page['batch']['currentItemId'])->toBe($page['items'][1]['id'])
        ->and($page['items'][0]['state'])->toBe('done')->and($page['items'][1]['state'])->toBe('waiting')
        ->and(array_keys($page['items'][1]))->toBe(['id', 'position', 'documentId', 'title', 'kind', 'state', 'rescanCount', 'duplicateOf'])
        ->and($page['current']['document']['title'])->toBe('Second')
        ->and($page['current']['adjustments'])->toBe(['brightness' => 100, 'contrast' => 100, 'bw' => false])
        ->and($page['limits'])->toBe(['maxFiles' => 50, 'maxMb' => (int) round(config('medical.document_max_bytes') / 1048576), 'maxRescans' => 5])
        ->and($page['ai']['available'])->toBeTrue()->and($page['ai']['noticeAcknowledged'])->toBeTrue()
        ->and($page['person'])->toBe(['id' => (string) $person->id, 'name' => 'Batch Person', 'aiConsent' => true])
        ->and($status['items'])->toBe($page['items'])->and($status['batch'])->toBe($page['batch'])
        ->and(array_keys($status))->toBe(['batch', 'items']);
});

it('opens on the stored cursor while it is unfinished and falls back to the first unfinished item', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);
    $a = $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'A', $user)['itemId'];
    $b = $q->addDocument($viewer, $person, $batch, ubQStored('b'), 'B', $user)['itemId'];

    expect($q->page($viewer, $person, $batch)['batch']['currentItemId'])->toBe($a)
        ->and($q->setCurrent($viewer, $person, $batch, $b))->toBeTrue()
        ->and($q->page($viewer, $person, $batch)['batch']['currentItemId'])->toBe($b)
        ->and($q->setCurrent($viewer, $person, $batch, (string) Str::uuid()))->toBeFalse();
    DB::table('medical_upload_batch_items')->where('id', $b)->update(['state' => 'done']);
    expect($q->page($viewer, $person, $batch)['batch']['currentItemId'])->toBe($a);
});

it('maps item and run status to the display state', function () {
    expect(ItemState::for('pending', null, null, true))->toBe('waiting')
        ->and(ItemState::for('pending', null, null, false))->toBe('consent_off')
        ->and(ItemState::for('sent', 'queued', null, true))->toBe('processing')
        ->and(ItemState::for('sent', 'running', null, true))->toBe('processing')
        ->and(ItemState::for('sent', 'waiting', null, true))->toBe('paused')
        ->and(ItemState::for('sent', 'ready', null, true))->toBe('review')
        ->and(ItemState::for('sent', 'failed', null, true))->toBe('failed')
        ->and(ItemState::for('sent', 'cancelled', 'access', true))->toBe('failed')
        ->and(ItemState::for('sent', 'cancelled', 'user', true))->toBe('stopped')
        ->and(ItemState::for('done', 'ready', null, true))->toBe('done');
});

it('shows paused with availableAt, failed with the code, access cancellations as failed and review with the pending count', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);
    $ids = [];
    foreach (['a', 'b', 'c', 'd'] as $i => $sha) {
        $added = $q->addDocument($viewer, $person, $batch, ubQStored($sha), "Doc $i", $user);
        $ids[] = $added;
    }
    $runs = [
        ['waiting', ['available_at' => now()->addMinutes(20), 'waiting_since' => now()]],
        ['failed', ['failure_code' => 'unreadable_document', 'finished_at' => now()]],
        ['cancelled', ['cancel_reason' => 'access', 'finished_at' => now()]],
        ['ready', ['payload' => json_encode(['visits' => [['visited_on' => '2026-01-01', 'doctor' => null, 'specialty_key' => 'cardiology']], 'medications' => [['name' => 'X']]]), 'entry_count' => 2, 'model' => 'default', 'finished_at' => now()]],
    ];
    foreach ($runs as $i => [$status, $extra]) {
        $eid = (string) Str::uuid();
        DB::table('medical_ai_extractions')->insert([
            'id' => $eid, 'subject_id' => $person->id, 'document_id' => $ids[$i]['documentId'], 'status' => $status, 'agent' => 'codex',
            'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $user->id, 'upload_batch_id' => $batch, 'queued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
        DB::table('medical_upload_batch_items')->where('id', $ids[$i]['itemId'])->update(['state' => 'sent', 'extraction_id' => $eid]);
    }
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $eid, 'entry_key' => 'medication-0', 'decision' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now()]);

    $items = $q->page($viewer, $person, $batch)['items'];

    expect($items[0]['state'])->toBe('paused')->and($items[0]['availableAt'])->toBeString()
        ->and($items[1]['state'])->toBe('failed')->and($items[1]['failureCode'])->toBe('unreadable_document')
        ->and($items[2]['state'])->toBe('failed')->and($items[2]['failureCode'])->toBe('access')
        ->and($items[3]['state'])->toBe('review')->and($items[3]['pending'])->toBe(1);
});

it('puts the review extraction of the current item on the page when its run is ready', function () {
    [$user, $person, $viewer] = ubQScene();
    $batch = ubQCreate($user, $person);
    $q = app(UploadBatchQuery::class);
    $added = $q->addDocument($viewer, $person, $batch, ubQStored('a'), 'Letter', $user);
    $eid = (string) Str::uuid();
    DB::table('medical_ai_extractions')->insert([
        'id' => $eid, 'subject_id' => $person->id, 'document_id' => $added['documentId'], 'status' => 'ready', 'agent' => 'codex',
        'schema_version' => 1, 'prompt_version' => 1, 'started_by' => $user->id, 'upload_batch_id' => $batch, 'queued_at' => now(),
        'payload' => json_encode(['medications' => [['name' => 'Ibuprofen', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]]]),
        'entry_count' => 1, 'model' => 'default', 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('medical_upload_batch_items')->where('id', $added['itemId'])->update(['state' => 'sent', 'extraction_id' => $eid]);

    $current = $q->page($viewer, $person, $batch)['current'];

    expect($current['review']['extraction']['id'])->toBe($eid)
        ->and($current['review']['extraction']['sections']['medications'][0]['key'])->toBe('medication-0')
        ->and($current['review']['extraction']['pending'])->toBe(1);
});
