<?php

use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Queries\AdminAiBulkQuery;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Medical\Queries\AiBulkEligibility;
use App\Medical\Queries\AiBulkJobQuery;
use App\Models\AiAgentSetting;
use App\Models\AiBulkRun;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

function bulkNoTextAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Bratislava')->utc();
}

function bulkNoTextTick(CarbonImmutable $now): array
{
    Carbon::setTestNow($now);

    return app(AiBulkJobQuery::class)->tick($now);
}

/** A document of a consenting person with a stored file. */
function bulkNoTextDoc(bool $consent = true, string $when = '2026-01-01 10:00:00', string $kind = 'image'): MedicalDocument
{
    $subject = MedicalFixtures::subject(['ai_consent' => $consent]);
    $factory = $kind === 'pdf' ? MedicalDocument::factory()->pdf() : MedicalDocument::factory()->image();
    $doc = $factory->forSubject($subject)->create(['created_at' => CarbonImmutable::parse($when)]);
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes');

    return $doc;
}

function bulkNoTextAccept(MedicalDocument $doc): MedicalAiExtraction
{
    $ext = MedicalAiExtraction::factory()->forDocument($doc)->ready()->create();
    DB::table('medical_ai_decisions')->insert([
        'extraction_id' => $ext->id, 'entry_key' => 'e1', 'decision' => 'accepted', 'record_type' => 'visit',
        'record_id' => (string) Str::uuid(), 'decided_by' => 1,
    ]);

    return $ext;
}

/** Starts a run of $scope through the real dry run and start. */
function bulkNoTextStart(User $admin, string $scope = 'no_text', int $cap = 5): array
{
    $q = app(AdminAiBulkQuery::class);
    $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
    DB::table('ai_notice_acks')->upsert([['user_id' => $admin->id, 'providers' => implode(',', $providers), 'acknowledged_at' => now()]], ['user_id'], ['providers', 'acknowledged_at']);
    $dry = $q->dryRun($admin, $scope, $cap);

    return isset($dry['token']) ? $q->start($admin, $dry['token'], true, $providers, true) : $dry;
}

function bulkNoTextBin(object $test, string $script): void
{
    $test->scripts[] = $script;
    config(['ai.codex.binary' => $script]);
}

function bulkNoTextPayload(): array
{
    return ['schema_version' => 1, 'document_date' => null, 'specialty_key' => null, 'visits' => [], 'medications' => [], 'allergies' => [], 'vaccinations' => []];
}

beforeEach(function () {
    Storage::fake('medical');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    $this->scripts = [];
    config(['ai.limits.document_cooldown_minutes' => 0, 'ai.ocr.enabled' => true]);
    $this->root = sys_get_temp_dir().'/bnt-work-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
        'ai.timeouts.abort_check_seconds' => 0.2,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    AiBulkJobQuery::$afterPick = null;
    foreach ($this->scripts as $f) {
        @unlink($f);
    }
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

it('accepts no_text in both scope checks and still refuses an unknown scope', function () {
    expect(AiBulkEligibility::SCOPES)->toBe(['no_proposal', 'no_text', 'last_failed', 'all']);
    $insert = fn (string $scope) => DB::table('ai_bulk_dry_runs')->insert([
        'id' => (string) Str::uuid(), 'requested_by' => $this->admin->id, 'scope' => $scope, 'daily_cap' => 5, 'eligible' => 0,
        'skipped_consent' => 0, 'skipped_unsupported' => 0, 'skipped_decided' => 0, 'skipped_active' => 0, 'created_at' => now(),
    ]);
    expect($insert('no_text'))->toBeTrue();
    // A savepoint: the failed statement must not poison the test transaction.
    expect(fn () => DB::transaction(fn () => $insert('nope')))->toThrow(QueryException::class);
    $run = AiBulkRun::factory()->create(['started_by' => $this->admin->id, 'scope' => 'no_text']);
    expect($run->scope)->toBe('no_text');
});

it('counts the no_text buckets and ignores the decided bucket', function () {
    $withText = bulkNoTextDoc();
    DocumentTextFixtures::save($withText, ['Page']);
    $accepted = bulkNoTextDoc();
    bulkNoTextAccept($accepted);
    $proposal = bulkNoTextDoc();
    MedicalAiExtraction::factory()->forDocument($proposal)->ready()->create();
    bulkNoTextDoc(consent: false);
    $active = bulkNoTextDoc();
    MedicalAiExtraction::factory()->forDocument($active)->running()->create();
    $archived = bulkNoTextDoc();
    DB::table('medical_documents')->where('id', $archived->id)->update(['archived_at' => now()]);
    $archivedPerson = bulkNoTextDoc();
    DB::table('medical_subjects')->where('id', $archivedPerson->subject_id)->update(['archived_at' => now()]);

    $counts = app(AiBulkEligibility::class)->counts('no_text');

    expect($counts)->toBe(['eligible' => 2, 'skipped_consent' => 1, 'skipped_unsupported' => 0, 'skipped_decided' => 0, 'skipped_active' => 1]);
    // The other scopes still skip a decided document.
    expect(app(AiBulkEligibility::class)->counts('all')['skipped_decided'])->toBe(1);
});

it('refuses the scope with ocr_off and writes nothing while the feature is off', function () {
    config(['ai.ocr.enabled' => false]);
    bulkNoTextDoc();
    $q = app(AdminAiBulkQuery::class);

    expect($q->dryRun($this->admin, 'no_text', 5))->toBe(['result' => 'ocr_off'])
        ->and(DB::table('ai_bulk_dry_runs')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'ai_bulk_run_changed')->count())->toBe(0)
        ->and($q->panel()['ocrEnabled'])->toBeFalse();

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('settings.ai_agents.bulk.dry_run'), ['scope' => 'no_text', 'cap' => 5])
        ->assertStatus(422)->assertJson(['reason' => 'ocr_off']);
    expect(DB::table('ai_bulk_dry_runs')->count())->toBe(0);
});

it('refuses a start of a no_text dry run when the feature was turned off in between', function () {
    bulkNoTextDoc();
    DB::table('ai_notice_acks')->insert(['user_id' => $this->admin->id, 'providers' => 'codex']);
    $q = app(AdminAiBulkQuery::class);
    $token = $q->dryRun($this->admin, 'no_text', 5)['token'];
    config(['ai.ocr.enabled' => false]);

    expect($q->start($this->admin, $token, true, ['codex']))->toBe(['result' => 'ocr_off'])
        ->and(AiBulkRun::query()->count())->toBe(0)
        ->and(DB::table('ai_bulk_dry_runs')->where('id', $token)->value('consumed_at'))->toBeNull();
});

it('holds a running no_text run while the feature is off and sends nothing', function () {
    bulkNoTextDoc();
    expect(bulkNoTextStart($this->admin)['result'])->toBe('ok');
    config(['ai.ocr.enabled' => false]);

    expect(bulkNoTextTick(bulkNoTextAt('2026-03-10 01:05'))['enqueued'])->toBeNull()
        ->and(DB::table('medical_ai_extractions')->count())->toBe(0)
        ->and(AiBulkRun::query()->value('status'))->toBe('active');
});

it('enqueues a text-only run, leaves proposals and accepted decisions alone and saves the text', function () {
    $accepted = bulkNoTextDoc(when: '2026-01-01 10:00:00');
    $acceptedExt = bulkNoTextAccept($accepted);
    $proposalDoc = bulkNoTextDoc(when: '2026-01-02 10:00:00');
    $proposal = MedicalAiExtraction::factory()->forDocument($proposalDoc)->ready(['entries' => [['note' => 'keep']]])->create();
    $before = DB::table('medical_ai_extractions')->whereIn('id', [$acceptedExt->id, $proposal->id])->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->status, $r->payload])->all();
    $decisions = DB::table('medical_ai_decisions')->count();
    $script = FakeCodex::transcribing(sys_get_temp_dir().'/bnt-state-'.bin2hex(random_bytes(4)), $log = sys_get_temp_dir().'/bnt-log-'.bin2hex(random_bytes(4)), 'Page', ['extract' => json_encode(bulkNoTextPayload())]);
    bulkNoTextBin($this, $script);
    $this->scripts[] = $log;

    expect(bulkNoTextStart($this->admin)['result'])->toBe('ok');
    $now = bulkNoTextAt('2026-03-10 01:05');
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $id = bulkNoTextTick($now)['enqueued'];
        expect($id)->not->toBeNull();
        $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
        expect($row->mode)->toBe('text')->and($row->bulk_run_id)->not->toBeNull();
        (new RunExtractionJob($id))->handle();
        $ids[] = $id;
        $now = $now->addMinutes(11);
    }
    $r = bulkNoTextTick($now);

    foreach ($ids as $id) {
        expect(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('text_saved');
    }
    expect($r['completed'])->toBe(1)
        ->and(DB::table('ai_bulk_run_items')->pluck('outcome')->all())->toBe(['read', 'read'])
        ->and(DocumentTextFixtures::row($accepted))->not->toBeNull()->and(DocumentTextFixtures::row($proposalDoc))->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->whereIn('id', [$acceptedExt->id, $proposal->id])->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->status, $r->payload])->all())->toBe($before)
        ->and(DB::table('medical_ai_decisions')->count())->toBe($decisions)
        ->and(array_filter(file($log) ?: [], fn ($l) => str_starts_with(trim($l), 'X')))->toBe([])
        ->and(DB::table('medical_visits')->count() + DB::table('medical_medications')->count())->toBe(0);
});

it('keeps the pacing guards of the other scopes', function () {
    bulkNoTextDoc(when: '2026-01-01 10:00:00');
    bulkNoTextDoc(when: '2026-01-02 10:00:00');
    bulkNoTextDoc(when: '2026-01-03 10:00:00');
    bulkNoTextStart($this->admin, 'no_text', 1);

    expect(bulkNoTextTick(bulkNoTextAt('2026-03-10 00:30'))['enqueued'])->toBeNull();
    $id = bulkNoTextTick(bulkNoTextAt('2026-03-10 01:05'))['enqueued'];
    expect($id)->not->toBeNull()
        ->and(bulkNoTextStart($this->admin, 'no_text', 1)['result'])->toBe('active')
        ->and(bulkNoTextTick(bulkNoTextAt('2026-03-10 01:06'))['enqueued'])->toBeNull();
    DB::table('medical_ai_extractions')->where('id', $id)->update(['status' => 'text_saved', 'finished_at' => now()]);
    // The daily cap of 1 is used up even after the spacing.
    expect(bulkNoTextTick(bulkNoTextAt('2026-03-10 02:30'))['enqueued'])->toBeNull();

    $run = AiBulkRun::query()->first();
    app(AdminAiBulkQuery::class)->pause($this->admin, $run->id);
    expect(bulkNoTextTick(bulkNoTextAt('2026-03-11 02:30'))['enqueued'])->toBeNull();
    app(AdminAiBulkQuery::class)->resume($this->admin, $run->id);
    expect(bulkNoTextTick(bulkNoTextAt('2026-03-11 02:30'))['enqueued'])->not->toBeNull();
});

it('parks a quota hit of a text item, shows waiting_quota and never starts Copilot', function () {
    bulkNoTextDoc();
    bulkNoTextBin($this, FakeCodex::quota('You have hit your usage limit. Try again later.'));
    config(['ai.copilot.binary' => '/nonexistent/copilot', 'ai.bulk.fallback_agents' => false]);
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    bulkNoTextStart($this->admin);
    $now = bulkNoTextAt('2026-03-10 01:05');
    $id = bulkNoTextTick($now)['enqueued'];

    (new RunExtractionJob($id))->handle();

    expect(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('waiting')
        ->and(DB::table('ai_calls')->where('extraction_id', $id)->where('agent', 'copilot')->count())->toBe(0)
        ->and(bulkNoTextTick($now->addMinutes(30))['enqueued'])->toBeNull()
        ->and(app(AdminAiBulkQuery::class)->panel()['active']['state'])->toBe('waiting_quota')
        ->and(DB::table('medical_document_texts')->count())->toBe(0);
});

it('applies the per-document cool-down after a text_saved run', function () {
    config(['ai.limits.document_cooldown_minutes' => 60]);
    $doc = bulkNoTextDoc();
    MedicalAiExtraction::factory()->forDocument($doc)->create(['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now()->subMinutes(5), 'payload' => null, 'entry_count' => null, 'model' => 'default']);
    bulkNoTextStart($this->admin);

    expect(app(AiBulkEligibility::class)->nextCandidate('no_text', (string) Str::uuid()))->toBeNull();
});

it('cancels a queued text run with consent when consent is withdrawn before the job and saves no text', function () {
    $doc = bulkNoTextDoc();
    $script = FakeCodex::transcribing(sys_get_temp_dir().'/bnt-state-'.bin2hex(random_bytes(4)), $log = sys_get_temp_dir().'/bnt-log-'.bin2hex(random_bytes(4)), 'Page');
    bulkNoTextBin($this, $script);
    $this->scripts[] = $log;
    bulkNoTextStart($this->admin);
    $id = bulkNoTextTick(bulkNoTextAt('2026-03-10 01:05'))['enqueued'];
    DB::table('medical_subjects')->where('id', $doc->subject_id)->update(['ai_consent' => false]);

    (new RunExtractionJob($id))->handle();

    $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('consent')
        ->and(DB::table('medical_document_texts')->count())->toBe(0)
        ->and(is_file($log) ? trim((string) file_get_contents($log)) : '')->toBe('');
});

it('cancels the queued and waiting text runs when the run is cancelled', function () {
    bulkNoTextDoc();
    bulkNoTextStart($this->admin);
    $id = bulkNoTextTick(bulkNoTextAt('2026-03-10 01:05'))['enqueued'];
    $run = AiBulkRun::query()->first();

    expect(app(AdminAiBulkQuery::class)->cancel($this->admin, $run->id)['result'])->toBe('ok')
        ->and(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('cancelled');
});

it('creates mode full for the other scopes while reading is on and mode entries while it is off', function () {
    bulkNoTextDoc(when: '2026-01-01 10:00:00');
    bulkNoTextStart($this->admin, 'all');
    $a = bulkNoTextTick(bulkNoTextAt('2026-03-10 01:05'))['enqueued'];
    expect(DB::table('medical_ai_extractions')->where('id', $a)->value('mode'))->toBe('full');

    DB::table('ai_bulk_runs')->update(['status' => 'cancelled', 'finished_at' => now()]);
    DB::table('medical_ai_extractions')->where('id', $a)->update(['status' => 'failed', 'failure_code' => 'worker_lost', 'finished_at' => now(), 'model' => 'default']);
    config(['ai.ocr.enabled' => false]);
    bulkNoTextStart($this->admin, 'all');
    $b = bulkNoTextTick(bulkNoTextAt('2026-03-10 02:30'))['enqueued'];
    expect($b)->not->toBeNull()->and(DB::table('medical_ai_extractions')->where('id', $b)->value('mode'))->toBe('entries');
});

it('validates the scope against the scope list and exposes only counts in the panel', function () {
    bulkNoTextDoc();
    $http = $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    $http->postJson(route('settings.ai_agents.bulk.dry_run'), ['scope' => 'nope', 'cap' => 5])->assertStatus(422);
    $http->postJson(route('settings.ai_agents.bulk.dry_run'), ['scope' => 'no_text', 'cap' => 5])->assertOk()->assertJsonPath('counts.eligible', 1)
        ->assertJsonPath('counts.skipped_decided', 0);
    expect(app(AdminAiBulkQuery::class)->panel()['ocrEnabled'])->toBeTrue();
});
