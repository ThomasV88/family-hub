<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Queries\AdminAiBulkQuery;
use App\Medical\Queries\AiBulkJobQuery;
use App\Medical\Queries\AiJobQuery;
use App\Models\AiAgentSetting;
use App\Models\AiBulkRun;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

function btAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Bratislava')->utc();
}

function btTick(CarbonImmutable $now): array
{
    Carbon::setTestNow($now);

    return app(AiBulkJobQuery::class)->tick($now);
}

/** @return array{doc: MedicalDocument, subject: MedicalSubject} */
function btDoc(bool $consent = true, string $when = '2026-01-01 10:00:00'): array
{
    $subject = MedicalFixtures::subject(['ai_consent' => $consent]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['created_at' => CarbonImmutable::parse($when)]);
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes');

    return ['doc' => $doc, 'subject' => $subject];
}

function btRun(array $attrs = []): AiBulkRun
{
    // The starter has acknowledged both providers, so the tick may use whichever is enabled (see the viability tests).
    DB::table('ai_notice_acks')->upsert([['user_id' => $attrs['started_by'] ?? test()->admin->id, 'providers' => 'codex,copilot', 'acknowledged_at' => now()]], ['user_id'], ['providers']);

    return AiBulkRun::factory()->create($attrs + ['started_by' => test()->admin->id, 'scope' => 'all', 'eligible_total' => 3]);
}

function btItems(AiBulkRun $run): array
{
    return DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)->orderBy('enqueued_at')->pluck('outcome')->all();
}

function btRecords(): array
{
    return [DB::table('medical_visits')->count(), DB::table('medical_medications')->count(), DB::table('medical_allergies')->count(),
        DB::table('medical_vaccinations')->count(), DB::table('medical_ai_decisions')->count(), DB::table('medical_documents')->count()];
}

function btFinish(string $id): void
{
    DB::table('medical_ai_extractions')->where('id', $id)->update([
        'status' => 'ready', 'payload' => json_encode(['entries' => []]), 'entry_count' => 0, 'model' => 'default', 'finished_at' => now(),
    ]);
}

function btAccept(MedicalDocument $doc): void
{
    $ext = MedicalAiExtraction::factory()->forDocument($doc)->ready()->create();
    DB::table('medical_ai_decisions')->insert([
        'extraction_id' => $ext->id, 'entry_key' => 'e1', 'decision' => 'accepted', 'record_type' => 'visit',
        'record_id' => (string) Str::uuid(), 'decided_by' => 1,
    ]);
}

beforeEach(function () {
    Storage::fake('medical');
    $this->admin = User::factory()->admin()->create();
    config(['ai.limits.document_cooldown_minutes' => 0]);
});

afterEach(function () {
    Carbon::setTestNow();
    AiBulkJobQuery::$afterPick = null;
    DB::purge('btlock');
});

it('waits for the off-peak hour, then queues one document with its audit trail, and keeps single flight', function () {
    $a = btDoc();
    $run = btRun();
    $before = btRecords();

    expect(btTick(btAt('2026-03-10 00:30'))['enqueued'])->toBeNull();

    $r = btTick(btAt('2026-03-10 01:05'));
    $row = DB::table('medical_ai_extractions')->where('id', $r['enqueued'])->first();
    $call = DB::table('ai_calls')->where('extraction_id', $r['enqueued'])->get();
    expect($row->status)->toBe('queued')->and($row->bulk_run_id)->toBe($run->id)->and($row->started_by)->toBe($this->admin->id)
        ->and($row->agent)->toBe('codex')->and($row->document_id)->toBe($a['doc']->id)
        ->and($call)->toHaveCount(1)->and($call[0]->attempt)->toBe(1)->and($call[0]->bulk_run_id)->toBe($run->id)
        ->and(DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_requested')->where('actor_id', $this->admin->id)->count())->toBe(1);

    expect(btTick(btAt('2026-03-10 01:06'))['enqueued'])->toBeNull()
        ->and(btRecords())->toBe($before);
});

it('keeps the spacing between documents and reconciles the finished one', function () {
    btDoc(when: '2026-01-01 10:00:00');
    btDoc(when: '2026-01-01 11:00:00');
    $run = btRun();
    $first = btTick(btAt('2026-03-10 01:05'))['enqueued'];
    btFinish($first);

    $r = btTick(btAt('2026-03-10 01:10'));
    expect($r['enqueued'])->toBeNull()->and($r['reconciled'])->toBe(1)->and(btItems($run))->toBe(['read']);

    expect(btTick(btAt('2026-03-10 01:16'))['enqueued'])->not->toBeNull();
});

it('stops at the daily cap until the next local day', function () {
    foreach ([10, 11, 12] as $h) {
        btDoc(when: "2026-01-01 $h:00:00");
    }
    btRun(['daily_cap' => 2]);
    foreach (['01:05', '01:20'] as $time) {
        $id = btTick(btAt("2026-03-10 $time"))['enqueued'];
        expect($id)->not->toBeNull();
        btFinish($id);
    }

    expect(btTick(btAt('2026-03-10 23:00'))['enqueued'])->toBeNull()
        ->and(btTick(btAt('2026-03-11 01:30'))['enqueued'])->not->toBeNull();
});

it('does nothing while ten extractions are active globally', function () {
    btDoc();
    btRun();
    foreach (range(1, 10) as $i) {
        MedicalAiExtraction::factory()->forDocument(btDoc()['doc'])->queued()->create();
    }
    $run = AiBulkRun::query()->first();
    // Those ten are not items of the run and are active, so the only eligible document is the first one.
    expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->toBeNull();
    config(['ai.bulk.max_global_active' => 11]);
    expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->not->toBeNull();
    expect($run)->not->toBeNull();
});

it('sets blocked_since while the only agent is cooling and clears it when the agent is back', function () {
    btDoc();
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    (new AgentHealth)->recordQuotaHit('codex', CarbonImmutable::parse('2026-03-10 05:00', 'UTC'), null, btAt('2026-03-10 01:00'));
    $run = btRun();

    expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->toBeNull()
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since'))->not->toBeNull();
    $since = DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since');

    btTick(btAt('2026-03-10 02:05'));
    expect(DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since'))->toBe($since);

    DB::table('ai_agent_status')->where('agent', 'codex')->update(['cooldown_until' => null]);
    expect(btTick(btAt('2026-03-10 08:00'))['enqueued'])->not->toBeNull()
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since'))->toBeNull();
});

it('holds the run visibly, instead of failing items, when the first agent is outside the starter\'s acknowledged set', function () {
    btDoc();
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true, 'position' => 1]);
    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['position' => 2]);
    $run = btRun();
    // The admin acknowledged OpenAI only, then reordered so that GitHub is first.
    DB::table('ai_notice_acks')->where('user_id', $this->admin->id)->update(['providers' => 'codex']);
    config(['ai.bulk.fallback_agents' => false]);

    $r = btTick(btAt('2026-03-10 02:00'));

    expect($r['enqueued'])->toBeNull()
        ->and(DB::table('medical_ai_extractions')->count())->toBe(0)
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('status'))->toBe('active')
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since'))->not->toBeNull();

    // With no acknowledgment at all the run is held as well; after the stop window the panel says Stopped.
    DB::table('ai_notice_acks')->where('user_id', $this->admin->id)->delete();
    btTick(btAt('2026-03-10 02:05'));
    Carbon::setTestNow(btAt('2026-03-10 02:05')->addHours((int) config('ai.bulk.stopped_after_hours') + 1));
    expect(app(AdminAiBulkQuery::class)->panel()['active']['state'])->toBe('stopped');

    // Fixing the acknowledgment lets the same run go on.
    DB::table('ai_notice_acks')->insert(['user_id' => $this->admin->id, 'providers' => 'codex,copilot', 'acknowledged_at' => now()]);
    expect(btTick(btAt('2026-03-10 20:00'))['enqueued'])->not->toBeNull()
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('blocked_since'))->toBeNull();
});

it('ends the run and its queued work when the starting admin is no longer an admin', function () {
    btDoc();
    $run = btRun();
    expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->not->toBeNull();
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);

    $r = btTick(btAt('2026-03-10 03:00'));

    expect($r['enqueued'])->toBeNull()
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('status'))->toBe('cancelled')
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('finished_at'))->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->value('status'))->toBe('cancelled')
        ->and(AuditEntry::where('event', 'ai_bulk_run_changed')->get()->pluck('meta.action')->all())->toContain('cancelled');
});

it('writes no person or document id into the audit rows of a bulk run and the audit page lists none', function () {
    $a = btDoc();
    $run = btRun();
    $id = btTick(btAt('2026-03-10 01:05'))['enqueued'];
    // A second, waiting bulk row that the reaper times out.
    $b = btDoc();
    $waiting = MedicalAiExtraction::factory()->forDocument($b['doc'])->waiting()->bulk($run->id)->create(['started_by' => $this->admin->id]);
    DB::table('medical_ai_extractions')->where('id', $waiting->id)->update(['waiting_since' => now()->subDays(3), 'available_at' => now()->subDays(2)]);
    app(AiJobQuery::class)->reapStuck(900, 6);
    app(AiBulkJobQuery::class)->cancelRunExtractions($run->id, $this->admin->id);

    $rows = DB::table('audit_logs')->whereIn('event', ['medical_ai_run_requested', 'medical_ai_run_finished'])->get();
    expect($rows->count())->toBeGreaterThanOrEqual(3);
    foreach ($rows as $row) {
        expect(json_decode($row->meta, true))->toHaveKey('bulk', true)->not->toHaveKeys(['subject', 'document']);
    }
    $ids = [$a['doc']->id, $a['subject']->id, $b['doc']->id, $b['subject']->id, $id];
    $page = $this->actingAs($this->admin)->get('/settings/audit')->assertOk()->getContent();
    foreach ($ids as $uuid) {
        expect($page)->not->toContain($uuid);
    }

    // An older row that still carries ids is masked by the controller once it is marked bulk.
    app(AuditLogger::class)->record(AuditEvent::MedicalAiRunFinished, $this->admin, ['bulk' => true, 'subject' => $a['subject']->id, 'document' => $a['doc']->id, 'status' => 'failed'], null, Module::Health);
    $page = $this->actingAs($this->admin)->get('/settings/audit')->assertOk()->getContent();
    expect($page)->not->toContain($a['subject']->id)->not->toContain($a['doc']->id);
});

it('does nothing for a paused or cancelled run and never completes a paused one', function () {
    btDoc();
    foreach (['paused', 'cancelled'] as $state) {
        DB::table('ai_bulk_runs')->delete();
        btRun(['status' => $state, 'finished_at' => $state === 'cancelled' ? now() : null, 'paused_at' => $state === 'paused' ? now() : null]);
        expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->toBeNull();
    }
    DB::table('ai_bulk_runs')->delete();
    $paused = btRun(['status' => 'paused', 'paused_at' => now(), 'eligible_total' => 0]);
    DB::table('medical_documents')->delete();

    expect(btTick(btAt('2026-03-10 02:00'))['completed'])->toBe(0)
        ->and(DB::table('ai_bulk_runs')->where('id', $paused->id)->value('status'))->toBe('paused');
});

it('completes when nothing is left and no item is open, before the pacing gates, with the audit row', function () {
    $a = btDoc();
    $run = btRun(['eligible_total' => 1]);
    $id = btTick(btAt('2026-03-10 01:05'))['enqueued'];
    btFinish($id);

    // 01:06 is inside the spacing window and the cap day: completion must not wait for the gates.
    $r = btTick(btAt('2026-03-10 01:06'));
    $row = DB::table('ai_bulk_runs')->where('id', $run->id)->first();
    expect($r['completed'])->toBe(1)->and($row->status)->toBe('completed')->and($row->finished_at)->not->toBeNull()->and($row->skipped_count)->toBe(0)
        ->and(btItems($run))->toBe(['read'])
        ->and(DB::table('audit_logs')->where('event', 'ai_bulk_run_changed')->where('actor_id', $this->admin->id)->count())->toBe(1)
        ->and($a['doc'])->not->toBeNull();
});

it('counts a skip once and completes the run (skip-then-complete)', function () {
    btDoc(when: '2026-01-01 10:00:00');
    $second = btDoc(when: '2026-01-01 11:00:00');
    $run = btRun(['eligible_total' => 2]);
    $firstId = btTick(btAt('2026-03-10 01:05'))['enqueued'];
    btFinish($firstId);

    // The second document loses consent between pick and insert.
    AiBulkJobQuery::$afterPick = fn () => DB::table('medical_subjects')->where('id', $second['subject']->id)->update(['ai_consent' => false]);
    $r = btTick(btAt('2026-03-10 01:20'));
    expect($r['enqueued'])->toBeNull()->and(DB::table('medical_ai_extractions')->count())->toBe(1);
    AiBulkJobQuery::$afterPick = null;

    $done = btTick(btAt('2026-03-10 01:21'));
    $row = DB::table('ai_bulk_runs')->where('id', $run->id)->first();
    expect($done['completed'])->toBe(1)->and($row->status)->toBe('completed')->and($row->skipped_count)->toBe(1)
        ->and(btItems($run))->toBe(['read', 'skipped']);
    $panel = app(AdminAiBulkQuery::class)->panel();
    expect($panel['history'][0]['skipped'])->toBe(1)->and($panel['history'][0]['read'])->toBe(1);
});

it('completes a run whose documents became ineligible after the dry run', function () {
    $keep = btDoc(when: '2026-01-01 10:00:00');
    $off = btDoc(when: '2026-01-01 11:00:00');
    $decided = btDoc(when: '2026-01-01 12:00:00');
    $run = btRun(['eligible_total' => 3]);
    DB::table('medical_subjects')->where('id', $off['subject']->id)->update(['ai_consent' => false]);
    btAccept($decided['doc']);

    $id = btTick(btAt('2026-03-10 01:05'))['enqueued'];
    expect(DB::table('medical_ai_extractions')->where('id', $id)->value('document_id'))->toBe($keep['doc']->id);
    btFinish($id);

    $done = btTick(btAt('2026-03-10 01:06'));
    $row = DB::table('ai_bulk_runs')->where('id', $run->id)->first();
    $panel = app(AdminAiBulkQuery::class)->panel();
    expect($done['completed'])->toBe(1)->and($row->status)->toBe('completed')->and($row->skipped_count)->toBe(2)
        ->and(DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)->count())->toBe(1)
        ->and($panel['history'][0])->toMatchArray(['wouldRead' => 3, 'read' => 1, 'status' => 'completed']);
});

it('never picks a document that lost consent or gained an accepted decision, and skips on an interactive race', function () {
    $off = btDoc();
    $decided = btDoc();
    btRun(['eligible_total' => 2]);
    DB::table('medical_subjects')->where('id', $off['subject']->id)->update(['ai_consent' => false]);
    btAccept($decided['doc']);
    $before = btRecords();

    $r = btTick(btAt('2026-03-10 02:00'));
    expect($r['enqueued'])->toBeNull()->and(DB::table('ai_bulk_run_items')->count())->toBe(0)
        ->and(DB::table('medical_ai_extractions')->count())->toBe(1)->and(btRecords())->toBe($before);

    // An interactive run slips in between the pick and the insert: one_active gives a skipped item.
    DB::table('ai_bulk_runs')->delete();
    DB::table('medical_ai_extractions')->delete();
    DB::table('medical_documents')->delete();
    $race = btDoc();
    $run = btRun(['eligible_total' => 1]);
    AiBulkJobQuery::$afterPick = fn () => MedicalAiExtraction::factory()->forDocument($race['doc'])->queued()->create();
    expect(btTick(btAt('2026-03-10 02:00'))['enqueued'])->toBeNull()
        ->and(btItems($run))->toBe(['skipped'])
        ->and(DB::table('medical_ai_extractions')->count())->toBe(1);
});

it('reconciles failed, cancelled, decided and deleted items, each skip once', function () {
    $run = btRun(['eligible_total' => 4]);
    $mk = function (string $state, array $args = []) use ($run) {
        $d = btDoc()['doc'];
        $f = MedicalAiExtraction::factory()->forDocument($d)->bulk($run->id);
        $ext = ($state === 'cancelled' ? $f->cancelled(...$args) : $f->failed())->create();
        DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run->id, 'document_id' => $d->id, 'extraction_id' => $ext->id]);

        return $d;
    };
    $mk('failed');
    $mk('cancelled', ['user']);
    $mk('cancelled', ['decided']);
    $gone = btDoc()['doc'];
    DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run->id, 'document_id' => $gone->id, 'extraction_id' => (string) Str::uuid()]);

    $r = btTick(btAt('2026-03-10 00:10'));
    expect($r['reconciled'])->toBe(4)
        ->and(DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)->orderBy('outcome')->pluck('outcome')->all())
        ->toBe(['cancelled', 'failed', 'gone', 'skipped'])
        ->and(btTick(btAt('2026-03-10 00:15'))['reconciled'])->toBe(0)
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('skipped_count'))->toBe(1);
});

it('holds the advisory lock for the tick transaction and runs a second tick as a no-op', function () {
    btDoc();
    btDoc();
    btRun();
    expect(btTick(btAt('2026-03-10 01:05'))['enqueued'])->not->toBeNull()
        ->and(btTick(btAt('2026-03-10 01:05'))['enqueued'])->toBeNull()
        ->and(DB::table('medical_ai_extractions')->count())->toBe(1);

    config(['database.connections.btlock' => config('database.connections.'.config('database.default'))]);
    $free = DB::connection('btlock')->selectOne("SELECT pg_try_advisory_xact_lock(hashtext('ai-bulk-tick')) AS got")->got;
    expect($free)->toBeFalse();
});

// Task 2: command, schedule, end to end

it('dispatches the job on the bulk queue and prints only numbers', function () {
    Queue::fake();
    btDoc();
    btRun();
    Carbon::setTestNow(btAt('2026-03-10 02:00'));

    Artisan::call('ai:bulk-tick');

    Queue::assertPushed(RunExtractionJob::class, fn (RunExtractionJob $j) => $j->connection === 'database' && $j->queue === 'ai-bulk');
    expect(trim(Artisan::output()))->toMatch('/^enqueued=1 reconciled=0 completed=0$/');
});

it('fails the queued extraction when the dispatch throws and reconciles the item to failed', function () {
    btDoc();
    $run = btRun();
    Carbon::setTestNow(btAt('2026-03-10 02:00'));
    // The command type-hints the bus contract: bind one that throws.
    $this->app->bind(Dispatcher::class, fn () => Mockery::mock(Dispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('down'))));

    Artisan::call('ai:bulk-tick');

    $ext = DB::table('medical_ai_extractions')->first();
    expect($ext->status)->toBe('failed')->and($ext->failure_code)->toBe('worker_lost');
    btTick(btAt('2026-03-10 02:05'));
    expect(btItems($run))->toBe(['failed']);
});

function btE2e(): array
{
    $admin = test()->admin;
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $a = btDoc();
    DB::table('ai_notice_acks')->insert(['user_id' => $admin->id, 'providers' => 'codex']);
    $admin2 = app(AdminAiBulkQuery::class);
    $token = $admin2->dryRun($admin, 'all', 5)['token'];
    expect($admin2->start($admin, $token, true, ['codex'])['result'])->toBe('ok');

    return $a;
}

function btPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [], 'medications' => [['name' => 'Enalapril', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

function btBin(string $agent, string $script): void
{
    $GLOBALS['bt_scripts'][] = $script;
    config(["ai.{$agent}.binary" => $script]);
}

describe('through the job', function () {
    beforeEach(function () {
        $this->root = sys_get_temp_dir().'/bt-work-'.strtolower((string) Str::ulid());
        $GLOBALS['bt_scripts'] = [];
        config([
            'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
            'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
        ]);
    });

    afterEach(function () {
        foreach ($GLOBALS['bt_scripts'] as $f) {
            @unlink($f);
        }
        if (is_dir($this->root)) {
            WorkDir::removeTree($this->root);
        }
    });

    it('reads a started run to the end with the golden result and completes it', function () {
        $a = btE2e();
        btBin('codex', FakeCodex::succeeding(json_encode(btPayload())));
        $now = btAt('2026-03-10 01:05');
        $id = btTick($now)['enqueued'];
        expect($id)->not->toBeNull();

        (new RunExtractionJob($id))->handle();
        expect(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('ready');

        $r = btTick($now->addMinutes(11));
        expect($r['reconciled'])->toBe(1)->and($r['completed'])->toBe(1)
            ->and(DB::table('ai_bulk_run_items')->where('document_id', $a['doc']->id)->value('outcome'))->toBe('read');
        // The job's finish row names no person or document either.
        $finished = DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->get();
        expect($finished)->toHaveCount(1)->and(json_decode($finished[0]->meta, true))->toEqual(['status' => 'succeeded', 'bulk' => true]);
    });

    it('parks on quota with the other agent cooling, shows waiting_quota and enqueues nothing meanwhile', function () {
        btDoc(when: '2026-01-01 12:00:00');
        btE2e();
        AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
        (new AgentHealth)->recordQuotaHit('copilot', CarbonImmutable::now()->addMinutes(40), null);
        btBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
        config(['ai.bulk.fallback_agents' => true]);
        $now = btAt('2026-03-10 01:05');
        $id = btTick($now)['enqueued'];

        (new RunExtractionJob($id))->handle();

        expect(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('waiting')
            ->and(btTick($now->addMinutes(30))['enqueued'])->toBeNull()
            ->and(app(AdminAiBulkQuery::class)->panel()['active']['state'])->toBe('waiting_quota');
    });

    it('cancels the new row as decided when an accepted decision appears after enqueue, leaving the old proposal alone', function () {
        $a = btE2e();
        btBin('codex', FakeCodex::succeeding(json_encode(btPayload())));
        $now = btAt('2026-03-10 01:05');
        $id = btTick($now)['enqueued'];
        btAccept($a['doc']);
        $old = DB::table('medical_ai_extractions')->where('id', '!=', $id)->first();

        (new RunExtractionJob($id))->handle();

        $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
        expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('decided')
            ->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe($old->status);
        $r = btTick($now->addMinutes(11));
        expect($r['reconciled'])->toBe(1)->and(btItems(AiBulkRun::query()->first()))->toBe(['skipped']);
    });
});
