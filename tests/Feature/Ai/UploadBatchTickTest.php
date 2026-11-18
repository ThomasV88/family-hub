<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Batches\BatchKicker;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\UploadBatchJobQuery;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('medical');
    config(['ai.limits.max_active_global' => 5, 'ai.limits.max_active_per_user' => 1]);
});

afterEach(function () {
    foreach ($GLOBALS['ub_scripts'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['ub_scripts'] = [];
    if (isset($this->ubRoot) && is_dir($this->ubRoot)) {
        WorkDir::removeTree($this->ubRoot);
    }
});

/**
 * An Edit member who acknowledged codex, a consenting person and an open batch of $n pending documents.
 *
 * @return array{user: User, subject: object, batch: string, items: list<string>, docs: list<string>}
 */
function ubTickBatch(int $n = 3, array $subject = []): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject($subject + ['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id,
        'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $items = [];
    $docs = [];
    for ($i = 1; $i <= $n; $i++) {
        $doc = MedicalDocument::factory()->image()->forSubject($person)->create(['upload_batch_id' => $batch, 'title' => 'SECRET-TITLE-'.$i]);
        Storage::disk('medical')->put($doc->path, 'jpeg-bytes');
        $item = (string) Str::uuid();
        DB::table('medical_upload_batch_items')->insert([
            'id' => $item, 'batch_id' => $batch, 'document_id' => $doc->id, 'position' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $items[] = $item;
        $docs[] = $doc->id;
    }

    return ['user' => $user, 'subject' => $person, 'batch' => $batch, 'items' => $items, 'docs' => $docs];
}

function ubTickAdvance(string $batch): ?string
{
    return app(UploadBatchJobQuery::class)->advance($batch, CarbonImmutable::now());
}

function ubTickItem(string $id): object
{
    return DB::table('medical_upload_batch_items')->where('id', $id)->first();
}

it('starts exactly one run for an open batch with all its audit and call rows, and none while it is active', function () {
    $s = ubTickBatch();

    $id = ubTickAdvance($s['batch']);

    $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($id)->not->toBeNull()
        ->and($row->status)->toBe('queued')->and($row->upload_batch_id)->toBe($s['batch'])->and($row->document_id)->toBe($s['docs'][0])
        ->and($row->fresh_read)->toBeFalse()->and($row->adjustments)->toBeNull()->and($row->bulk_run_id)->toBeNull()
        ->and($row->started_by)->toBe($s['user']->id)->and($row->agent)->toBe('codex')
        ->and(DB::table('ai_calls')->where('extraction_id', $id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_requested')->count())->toBe(1)
        ->and(ubTickItem($s['items'][0])->state)->toBe('sent')->and(ubTickItem($s['items'][0])->extraction_id)->toBe($id)
        ->and(ubTickItem($s['items'][1])->state)->toBe('pending');

    expect(ubTickAdvance($s['batch']))->toBeNull()
        ->and(DB::table('medical_ai_extractions')->count())->toBe(1);
});

it('sends a re-scan before documents never read, oldest request first, then in upload order', function () {
    $s = ubTickBatch(4);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][3])->update(['rescan_requested_at' => now()->subMinutes(5)]);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][2])->update(['rescan_requested_at' => now()->subMinutes(10)]);

    $order = [];
    foreach ([0, 1, 2, 3] as $_) {
        $id = ubTickAdvance($s['batch']);
        $order[] = DB::table('medical_ai_extractions')->where('id', $id)->value('document_id');
        DB::table('medical_ai_extractions')->where('id', $id)->update(['status' => 'ready', 'payload' => '{}', 'entry_count' => 0, 'model' => 'default', 'finished_at' => now()]);
    }

    expect($order)->toBe([$s['docs'][2], $s['docs'][3], $s['docs'][0], $s['docs'][1]]);
});

it('marks a re-scan as a fresh read and carries non-neutral adjustments, the requester being the starter', function () {
    $s = ubTickBatch(1);
    $other = User::factory()->create();
    MedicalFixtures::grantHealth($other, AccessLevel::Edit);
    DB::table('medical_subject_members')->insert(['subject_id' => $s['subject']->id, 'user_id' => $other->id, 'level' => 2, 'created_at' => now()]);
    DB::table('ai_notice_acks')->insert(['user_id' => $other->id, 'providers' => 'codex']);
    DB::table('medical_upload_batch_items')->where('id', $s['items'][0])->update([
        'rescan_requested_at' => now(), 'requested_by' => $other->id, 'adjustments' => json_encode(['brightness' => 120, 'contrast' => 100, 'bw' => true]),
    ]);

    $id = ubTickAdvance($s['batch']);

    $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($row->fresh_read)->toBeTrue()->and($row->started_by)->toBe($other->id)
        ->and(json_decode($row->adjustments, true))->toEqual(['brightness' => 120, 'contrast' => 100, 'bw' => true])
        ->and(ubTickItem($s['items'][0])->rescan_requested_at)->toBeNull();
});

it('starts nothing and writes nothing when a guard says no', function (Closure $break) {
    $s = ubTickBatch(2);
    $break($s);

    expect(ubTickAdvance($s['batch']))->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('upload_batch_id', $s['batch'])->count())->toBe(0)
        ->and(DB::table('ai_calls')->where('subject_id', $s['subject']->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_requested')->count())->toBe(0)
        ->and(DB::table('medical_upload_batch_items')->where('state', 'pending')->count())->toBe(2);
})->with([
    'batch finished' => [fn ($s) => DB::table('medical_upload_batches')->where('id', $s['batch'])->update(['status' => 'finished', 'finished_at' => now()])],
    'consent off' => [fn ($s) => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false])],
    'person archived' => [fn ($s) => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['archived_at' => now()])],
    'first document archived and second too' => [fn ($s) => DB::table('medical_documents')->whereIn('id', $s['docs'])->update(['archived_at' => now()])],
    'starter lost membership' => [fn ($s) => DB::table('medical_subject_members')->where('subject_id', $s['subject']->id)->delete()],
    'starter lost the Health module' => [function ($s) {
        MedicalFixtures::grantHealth($s['user'], AccessLevel::View);
    }],
    'starter is gone' => [fn ($s) => DB::table('medical_upload_batches')->where('id', $s['batch'])->update(['acted_by' => null])],
    'no acknowledged provider' => [fn ($s) => DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->delete()],
    'global cap reached' => [function ($s) {
        config(['ai.limits.max_active_global' => 1]);
        $other = MedicalDocument::factory()->image()->forSubject(MedicalFixtures::subject(['ai_consent' => true]))->create();
        DB::table('medical_ai_extractions')->insert([
            'id' => (string) Str::uuid(), 'subject_id' => $other->subject_id, 'document_id' => $other->id, 'status' => 'queued', 'agent' => 'codex',
            'schema_version' => 1, 'prompt_version' => 1, 'mode' => 'entries', 'started_by' => $s['user']->id, 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }],
]);

it('does not count batch runs toward the member personal active limit', function () {
    $s = ubTickBatch(1);
    expect(ubTickAdvance($s['batch']))->not->toBeNull();

    $mine = MedicalDocument::factory()->image()->forSubject($s['subject'])->create();
    Storage::disk('medical')->put($mine->path, 'jpeg-bytes');
    $viewer = App\Medical\Viewer::for($s['user']);
    $result = app(App\Medical\Queries\AiRunQuery::class)->start($viewer, $s['subject'], $mine->id, $s['user'], true, ['codex'], true);

    expect($result->outcome)->toBe('queued');
});

it('keeps a quota-parked batch still, then resumes and moves to the next document by itself', function () {
    $this->ubRoot = sys_get_temp_dir().'/ub-work-'.strtolower((string) Str::ulid());
    config(['ai.work_dir' => $this->ubRoot, 'ai.codex.home' => $this->ubRoot.'-codex', 'ai.codex.model' => null, 'ai.ocr.enabled' => false]);
    $s = ubTickBatch(2);
    $payload = json_encode([
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [], 'medications' => [], 'allergies' => [], 'vaccinations' => [],
    ]);

    $bin = FakeCodex::quota('You have hit your usage limit. Try again later.');
    $GLOBALS['ub_scripts'][] = $bin;
    config(['ai.codex.binary' => $bin]);
    $first = ubTickAdvance($s['batch']);
    (new RunExtractionJob($first))->handle();
    $row = DB::table('medical_ai_extractions')->where('id', $first)->first();
    expect($row->status)->toBe('waiting');

    // Parked: the batch starts no further document.
    expect(ubTickAdvance($s['batch']))->toBeNull()
        ->and(ubTickItem($s['items'][1])->state)->toBe('pending');

    // The quota is back: the existing resume path requeues the run, the job reads the document.
    DB::table('ai_agent_status')->update(['cooldown_until' => null]);
    DB::table('medical_ai_extractions')->where('id', $first)->update(['available_at' => now()->subMinute()]);
    Queue::fake();
    Artisan::call('ai:resume-waiting');
    expect(DB::table('medical_ai_extractions')->where('id', $first)->value('status'))->toBe('queued');
    $ok = FakeCodex::succeeding($payload);
    $GLOBALS['ub_scripts'][] = $ok;
    config(['ai.codex.binary' => $ok]);
    (new RunExtractionJob($first))->handle();
    expect(DB::table('medical_ai_extractions')->where('id', $first)->value('status'))->toBe('ready');

    $second = ubTickAdvance($s['batch']);
    expect($second)->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('id', $second)->value('document_id'))->toBe($s['docs'][1])
        ->and(ubTickItem($s['items'][0])->state)->toBe('sent');
});

it('ticks every open batch once, dispatches on the interactive queue and prints counts only', function () {
    Queue::fake();
    $a = ubTickBatch(2);
    $b = ubTickBatch(1);

    $this->artisan('ai:upload-batch-tick')->expectsOutput('enqueued=2')->assertExitCode(0);

    Queue::assertPushed(RunExtractionJob::class, 2);
    Queue::assertPushedOn((string) config('ai.queue'), RunExtractionJob::class);
    expect(DB::table('medical_ai_extractions')->whereIn('upload_batch_id', [$a['batch'], $b['batch']])->count())->toBe(2);

    // Second tick: both batches have an active run.
    $this->artisan('ai:upload-batch-tick')->expectsOutput('enqueued=0')->assertExitCode(0);
    Artisan::call('ai:upload-batch-tick');
    expect(Artisan::output())->not->toMatch('/[0-9a-f]{8}-[0-9a-f]{4}-/')->not->toContain('SECRET');
});

it('fails the queued run when the dispatch throws', function () {
    $s = ubTickBatch(1);
    $this->mock(Dispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down')));

    expect(app(BatchKicker::class)->kick($s['batch']))->toBeTrue();

    $row = DB::table('medical_ai_extractions')->where('upload_batch_id', $s['batch'])->first();
    expect($row->status)->toBe('failed');
});

it('has the pure helpers of the shared notice decision', function () {
    $s = ubTickBatch(1);
    $viewer = App\Medical\Viewer::for($s['user']);
    $runs = app(App\Medical\Queries\AiRunQuery::class);
    DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->delete();
    config(['ai.ocr.enabled' => false]);

    DB::transaction(function () use ($runs, $viewer) {
        expect($runs->acknowledgmentNeeded($viewer, false, null, false))->toBe(['codex'])
            ->and($runs->acknowledgmentNeeded($viewer, true, ['copilot'], false))->toBe(['codex'])
            ->and($runs->acknowledgmentNeeded($viewer, true, ['codex'], false))->toBeNull();
        $runs->recordAcknowledgment($viewer, ['codex']);
        expect($runs->acknowledgmentNeeded($viewer, false, null, false))->toBeNull();
    });
    expect(DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->value('providers'))->toBe('codex');
});

it('reuses the job query job failQueued for a missing run without error', function () {
    expect(app(AiJobQuery::class)->failQueued((string) Str::uuid()))->toBeFalse();
});
