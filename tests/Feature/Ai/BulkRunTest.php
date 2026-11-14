<?php

use App\Audit\AuditLogger;
use App\Medical\Queries\AdminAiBulkQuery;
use App\Medical\Queries\AiBulkEligibility;
use App\Medical\Queries\AiBulkJobQuery;
use App\Models\AiBulkRun;
use App\Models\AiCall;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalPrivacy;
use Tests\Support\Roles;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function bkAdmin(): User
{
    return User::factory()->admin()->create();
}

/** @param array<string, mixed> $doc */
function bkDoc(bool $consent = true, string $kind = 'pdf', array $doc = [], ?CarbonImmutable $createdAt = null): MedicalDocument
{
    $subject = MedicalSubject::factory()->create(['ai_consent' => $consent, 'name' => 'Person '.MedicalPrivacy::CANARY]);
    $factory = MedicalDocument::factory()->forSubject($subject);
    $factory = match ($kind) {
        'image' => $factory->image(),
        'heic' => $factory->heic(),
        default => $factory->pdf(),
    };

    return $factory->create($doc + ['title' => 'Title '.MedicalPrivacy::CANARY] + ($createdAt === null ? [] : ['created_at' => $createdAt]));
}

function bkExt(MedicalDocument $doc, string $state, array $attrs = []): MedicalAiExtraction
{
    $factory = MedicalAiExtraction::factory()->forDocument($doc);
    $factory = match ($state) {
        'ready' => $factory->ready(['entries' => [['note' => MedicalPrivacy::CANARY]]]),
        'superseded' => $factory->superseded(['entries' => [['note' => MedicalPrivacy::CANARY]]]),
        'failed' => $factory->failed(),
        'waiting' => $factory->waiting(),
        'running' => $factory->running(),
        default => $factory->queued(),
    };

    return $factory->create($attrs);
}

function bkDecision(MedicalAiExtraction $ext, string $decision): void
{
    DB::table('medical_ai_decisions')->insert([
        'extraction_id' => $ext->id, 'entry_key' => 'e1', 'decision' => $decision,
        'record_type' => $decision === 'accepted' ? 'visit' : null,
        'record_id' => $decision === 'accepted' ? (string) Str::uuid() : null,
        'decided_by' => 1,
    ]);
}

/** @return array<string, MedicalDocument> the bucket fixture of the plan */
function bkFixture(): array
{
    $t = CarbonImmutable::parse('2026-01-01 10:00:00');
    $f = [];
    $f['eligible'] = bkDoc(createdAt: $t);
    $f['consent'] = bkDoc(false, 'image', createdAt: $t->addMinute());
    $f['heic'] = bkDoc(false, 'heic', createdAt: $t->addMinutes(2));
    $f['decided'] = bkDoc(createdAt: $t->addMinutes(3));
    bkDecision(bkExt($f['decided'], 'superseded'), 'accepted');
    bkExt($f['decided'], 'ready');
    $f['active'] = bkDoc(createdAt: $t->addMinutes(4));
    bkExt($f['active'], 'waiting');
    $f['ready'] = bkDoc(createdAt: $t->addMinutes(5));
    bkExt($f['ready'], 'ready');
    $f['failed'] = bkDoc(createdAt: $t->addMinutes(6));
    bkExt($f['failed'], 'failed');
    $f['rejected'] = bkDoc(createdAt: $t->addMinutes(7));
    bkDecision(bkExt($f['rejected'], 'ready'), 'rejected');

    return $f;
}

function bkItem(AiBulkRun $run, MedicalDocument $doc, ?string $outcome = null, ?string $extractionId = null): void
{
    DB::table('ai_bulk_run_items')->insert([
        'bulk_run_id' => $run->id, 'document_id' => $doc->id, 'extraction_id' => $extractionId, 'outcome' => $outcome,
    ]);
}

function bkQuery(): AdminAiBulkQuery
{
    return app(AdminAiBulkQuery::class);
}

// Task 1: eligibility counts, candidates, cancel of a run's work

it('counts the exclusive buckets per scope', function () {
    bkFixture();
    $e = app(AiBulkEligibility::class);

    expect($e->counts('no_proposal'))->toBe(['eligible' => 2, 'skipped_consent' => 1, 'skipped_unsupported' => 1, 'skipped_decided' => 0, 'skipped_active' => 1])
        ->and($e->counts('last_failed'))->toBe(['eligible' => 1, 'skipped_consent' => 0, 'skipped_unsupported' => 0, 'skipped_decided' => 0, 'skipped_active' => 0])
        ->and($e->counts('all'))->toBe(['eligible' => 4, 'skipped_consent' => 1, 'skipped_unsupported' => 1, 'skipped_decided' => 1, 'skipped_active' => 1]);
});

it('does not treat a document with only rejected decisions as decided', function () {
    $d = bkDoc();
    bkDecision(bkExt($d, 'ready'), 'rejected');

    expect(app(AiBulkEligibility::class)->counts('all'))->toMatchArray(['eligible' => 1, 'skipped_decided' => 0]);
});

it('returns only the five integer keys', function () {
    bkFixture();
    $counts = app(AiBulkEligibility::class)->counts('all');

    expect(array_keys($counts))->toBe(['eligible', 'skipped_consent', 'skipped_unsupported', 'skipped_decided', 'skipped_active'])
        ->and(array_filter($counts, fn ($v) => ! is_int($v)))->toBe([]);
});

it('picks the next eligible document by age, outside the run and the cool-down', function () {
    $f = bkFixture();
    $run = AiBulkRun::factory()->create(['scope' => 'all']);
    $e = app(AiBulkEligibility::class);

    // The ready documents finished moments ago: under the per-document cool-down.
    $first = $e->nextCandidate('all', $run->id);
    expect($first)->toBe(['document_id' => $f['eligible']->id, 'subject_id' => $f['eligible']->subject_id]);

    bkItem($run, $f['eligible']);
    expect($e->nextCandidate('all', $run->id)['document_id'])->toBe($f['failed']->id);

    bkItem($run, $f['failed']);
    expect($e->nextCandidate('all', $run->id))->toBeNull();

    // Once the cool-down has passed, the ready documents are candidates again.
    DB::table('medical_ai_extractions')->where('document_id', $f['ready']->id)->update(['finished_at' => now()->subMinutes(11)]);
    expect($e->nextCandidate('all', $run->id)['document_id'])->toBe($f['ready']->id);
});

it('cancels a run\'s queued, waiting and running work and nothing else', function () {
    $run = AiBulkRun::factory()->create();
    $admin = bkAdmin();
    $queued = bkExt(bkDoc(), 'queued', ['bulk_run_id' => $run->id, 'started_by' => $admin->id]);
    $waiting = bkExt(bkDoc(), 'waiting', ['bulk_run_id' => $run->id, 'started_by' => $admin->id]);
    $running = bkExt(bkDoc(), 'running', ['bulk_run_id' => $run->id, 'started_by' => $admin->id]);
    $readyDoc = bkDoc();
    $ready = bkExt($readyDoc, 'ready', ['bulk_run_id' => $run->id]);
    $other = bkExt(bkDoc(), 'queued');
    foreach ([$queued, $waiting] as $row) {
        AiCall::factory()->create(['extraction_id' => $row->id, 'subject_id' => $row->subject_id, 'document_id' => $row->document_id, 'bulk_run_id' => $run->id]);
        DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run->id, 'document_id' => $row->document_id, 'extraction_id' => $row->id]);
    }
    $since = DB::table('medical_ai_extractions')->where('id', $waiting->id)->value('waiting_since');
    $records = fn () => [DB::table('medical_visits')->count(), DB::table('medical_medications')->count(), DB::table('medical_allergies')->count(),
        DB::table('medical_vaccinations')->count(), DB::table('medical_ai_decisions')->count(), DB::table('medical_documents')->count()];
    $before = $records();

    $count = app(AiBulkJobQuery::class)->cancelRunExtractions($run->id, $admin->id);

    $row = fn (string $id) => DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($count)->toBe(3)
        ->and($row($queued->id)->status)->toBe('cancelled')
        ->and($row($queued->id)->cancel_reason)->toBe('user')
        ->and($row($waiting->id)->status)->toBe('cancelled')
        ->and($row($waiting->id)->available_at)->toBeNull()
        ->and($row($waiting->id)->waiting_since)->toBe($since)
        ->and($row($running->id)->status)->toBe('running')
        ->and($row($running->id)->cancel_requested_at)->not->toBeNull()
        ->and($row($ready->id)->status)->toBe('ready')
        ->and($row($other->id)->status)->toBe('queued')
        ->and(DB::table('ai_calls')->whereIn('extraction_id', [$queued->id, $waiting->id])->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(AuditEntry::where('event', 'medical_ai_run_finished')->count())->toBe(2)
        ->and(DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)->pluck('outcome')->unique()->all())->toBe(['cancelled'])
        ->and($records())->toBe($before);
});

// Task 2: dry run, start, controls, panel

it('stores a dry run with the counts and audits it first', function () {
    bkFixture();
    $admin = bkAdmin();

    $dry = bkQuery()->dryRun($admin, 'all', 7);

    expect($dry['validMinutes'])->toBe(15)
        ->and($dry['counts']['eligible'])->toBe(4)
        ->and(Str::isUuid($dry['token']))->toBeTrue()
        ->and(DB::table('ai_bulk_dry_runs')->where('id', $dry['token'])->first())
        ->requested_by->toBe($admin->id)
        ->scope->toBe('all')
        ->daily_cap->toBe(7)
        ->eligible->toBe(4)
        ->consumed_at->toBeNull();
    $audit = AuditEntry::where('event', 'ai_bulk_run_changed')->first();
    expect($audit->meta)->toEqual(['action' => 'dry_run', 'scope' => 'all', 'count' => 4]);
});

it('leaves no dry run behind when the audit write fails', function () {
    $this->app->instance(AuditLogger::class, new FailingAuditLogger);

    expect(fn () => bkQuery()->dryRun(bkAdmin(), 'all', 5))->toThrow(RuntimeException::class)
        ->and(DB::table('ai_bulk_dry_runs')->count())->toBe(0);
});

it('starts a run from a fresh token with scope and cap from the token', function () {
    bkFixture();
    DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['enabled' => true]);
    $admin = bkAdmin();
    $dry = bkQuery()->dryRun($admin, 'no_proposal', 9);

    $result = bkQuery()->start($admin, $dry['token'], true);

    $run = DB::table('ai_bulk_runs')->first();
    expect($result['result'])->toBe('ok')
        ->and($result['panel']['active']['scope'])->toBe('no_proposal')
        ->and($run->status)->toBe('active')
        ->and($run->scope)->toBe('no_proposal')
        ->and($run->daily_cap)->toBe(9)
        ->and($run->eligible_total)->toBe(2)
        ->and($run->dry_run_id)->toBe($dry['token'])
        ->and($run->started_by)->toBe($admin->id)
        ->and(DB::table('ai_bulk_dry_runs')->where('id', $dry['token'])->value('consumed_at'))->not->toBeNull()
        ->and(DB::table('ai_notice_acks')->where('user_id', $admin->id)->value('providers'))->toBe('codex,copilot')
        ->and(AuditEntry::where('event', 'ai_bulk_run_changed')->get()->pluck('meta.action')->all())->toBe(['dry_run', 'started']);
});

it('refuses a reused, old or foreign token and a changed eligible count', function () {
    bkDoc();
    $admin = bkAdmin();
    $q = bkQuery();

    $dry = $q->dryRun($admin, 'all', 5);
    expect($q->start($admin, $dry['token'], true)['result'])->toBe('ok')
        ->and($q->start($admin, $dry['token'], true)['result'])->toBe('expired');
    DB::table('ai_bulk_runs')->update(['status' => 'cancelled', 'finished_at' => now()]);

    $old = $q->dryRun($admin, 'all', 5);
    DB::table('ai_bulk_dry_runs')->where('id', $old['token'])->update(['created_at' => now()->subMinutes(16)]);
    expect($q->start($admin, $old['token'], true)['result'])->toBe('expired');

    $justInside = $q->dryRun($admin, 'all', 5);
    DB::table('ai_bulk_dry_runs')->where('id', $justInside['token'])->update(['created_at' => now()->subMinutes(14)]);
    $foreign = $q->dryRun($admin, 'all', 5);
    expect($q->start(bkAdmin(), $foreign['token'], true)['result'])->toBe('expired')
        ->and($q->start($admin, (string) Str::uuid(), true)['result'])->toBe('expired')
        ->and($q->start($admin, 'not-a-uuid', true)['result'])->toBe('expired');

    bkDoc();
    expect($q->start($admin, $justInside['token'], true)['result'])->toBe('stale')
        ->and(DB::table('ai_bulk_runs')->where('status', 'active')->count())->toBe(0)
        ->and(DB::table('ai_bulk_dry_runs')->where('id', $justInside['token'])->value('consumed_at'))->toBeNull();
});

it('yields one run when a second fresh token starts while one is open', function () {
    bkDoc();
    $admin = bkAdmin();
    $other = bkAdmin();
    $q = bkQuery();
    $a = $q->dryRun($admin, 'all', 5);
    $b = $q->dryRun($other, 'all', 5);

    expect($q->start($admin, $a['token'], true)['result'])->toBe('ok');
    $second = $q->start($other, $b['token'], true);

    expect($second['result'])->toBe('active')
        ->and($second['panel']['active'])->not->toBeNull()
        ->and(DB::table('ai_bulk_runs')->count())->toBe(1)
        ->and(DB::table('ai_bulk_dry_runs')->where('id', $b['token'])->value('consumed_at'))->toBeNull();
});

it('pauses, resumes and refuses an invalid transition', function () {
    $admin = bkAdmin();
    $run = AiBulkRun::factory()->create();
    $q = bkQuery();

    expect($q->pause($admin, $run->id)['result'])->toBe('ok')
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('status'))->toBe('paused')
        ->and($q->pause($admin, $run->id)['result'])->toBe('conflict')
        ->and($q->resume($admin, $run->id)['result'])->toBe('ok')
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('status'))->toBe('active')
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->value('paused_at'))->toBeNull()
        ->and($q->resume($admin, $run->id)['result'])->toBe('conflict')
        ->and($q->pause($admin, (string) Str::uuid())['result'])->toBe('missing')
        ->and(AuditEntry::where('event', 'ai_bulk_run_changed')->get()->pluck('meta.action')->all())->toBe(['paused', 'resumed']);
});

it('cancels the run and stops its queued and waiting items', function () {
    $admin = bkAdmin();
    $run = AiBulkRun::factory()->paused()->create();
    $queued = bkExt(bkDoc(), 'queued', ['bulk_run_id' => $run->id]);
    $waiting = bkExt(bkDoc(), 'waiting', ['bulk_run_id' => $run->id]);

    $result = bkQuery()->cancel($admin, $run->id);

    expect($result['result'])->toBe('ok')
        ->and($result['panel']['active'])->toBeNull()
        ->and(DB::table('ai_bulk_runs')->where('id', $run->id)->first())->status->toBe('cancelled')->finished_at->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->whereIn('id', [$queued->id, $waiting->id])->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(AuditEntry::where('event', 'ai_bulk_run_changed')->first()->meta)->toEqual(['action' => 'cancelled', 'scope' => 'no_proposal', 'count' => 2])
        ->and(bkQuery()->cancel($admin, $run->id)['result'])->toBe('conflict');
});

it('derives the panel state and counts from items only', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:00', 'Europe/Bratislava'));
    $run = AiBulkRun::factory()->create(['eligible_total' => 10, 'daily_cap' => 20]);
    $read = bkDoc();
    $failed = bkDoc();
    $skipped = bkDoc();
    $open = bkDoc();
    bkItem($run, $read, 'read');
    bkItem($run, $failed, 'failed');
    bkItem($run, $skipped, 'skipped');
    $inflight = bkExt($open, 'running', ['bulk_run_id' => $run->id]);
    bkItem($run, $open, null, $inflight->id);

    $active = bkQuery()->panel()['active'];

    expect($active)->toMatchArray([
        'id' => $run->id, 'status' => 'active', 'state' => 'running', 'scope' => 'no_proposal', 'dailyCap' => 20, 'total' => 10,
        'done' => 3, 'read' => 1, 'failed' => 1, 'skipped' => 1, 'waiting' => 0, 'left' => 6, 'todayUsed' => 4,
        'nextBatchAfter' => null, 'availableAt' => null,
    ]);

    DB::table('ai_bulk_runs')->where('id', $run->id)->update(['status' => 'paused', 'paused_at' => now()]);
    expect(bkQuery()->panel()['active']['state'])->toBe('paused');
});

it('shows next_batch before the start hour and after the cap is used', function () {
    $run = AiBulkRun::factory()->create(['daily_cap' => 1]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-11-14 00:30:00', 'Europe/Bratislava'));
    $before = bkQuery()->panel()['active'];
    expect($before['state'])->toBe('next_batch')
        ->and(CarbonImmutable::parse($before['nextBatchAfter'])->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-11-14 01:00');

    Carbon::setTestNow(CarbonImmutable::now('Europe/Bratislava')->setTime(12, 0));
    DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run->id, 'document_id' => (string) Str::uuid(), 'enqueued_at' => now()->subMinutes(5)]);
    $used = bkQuery()->panel()['active'];
    expect($used['state'])->toBe('next_batch')
        ->and($used['todayUsed'])->toBe(1)
        ->and(CarbonImmutable::parse($used['nextBatchAfter'])->setTimezone('Europe/Bratislava')->format('H:i'))->toBe('01:00')
        ->and(CarbonImmutable::parse($used['nextBatchAfter'])->gt(now()))->toBeTrue();
});

it('shows waiting_quota with the time the waiting item is due', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:00', 'Europe/Bratislava'));
    $run = AiBulkRun::factory()->create();
    $doc = bkDoc();
    $due = now()->addMinutes(45);
    $ext = bkExt($doc, 'waiting', ['bulk_run_id' => $run->id, 'available_at' => $due]);
    bkItem($run, $doc, null, $ext->id);

    $active = bkQuery()->panel()['active'];

    expect($active['state'])->toBe('waiting_quota')
        ->and($active['waiting'])->toBe(1)
        ->and(CarbonImmutable::parse($active['availableAt'])->equalTo($due))->toBeTrue();
});

it('shows stopped once blocked for more than the configured hours', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:00', 'Europe/Bratislava'));
    $run = AiBulkRun::factory()->create(['blocked_since' => now()->subHours(25)]);

    expect(bkQuery()->panel()['active']['state'])->toBe('stopped');

    DB::table('ai_bulk_runs')->where('id', $run->id)->update(['blocked_since' => now()->subHours(23)]);
    expect(bkQuery()->panel()['active']['state'])->toBe('running');
});

it('lists at most ten history rows, newest first, with counts only', function () {
    foreach (range(1, 12) as $i) {
        AiBulkRun::factory()->completed()->create(['created_at' => now()->subDays(20 - $i), 'eligible_total' => $i]);
    }

    $history = bkQuery()->panel()['history'];

    expect($history)->toHaveCount(10)
        ->and($history[0]['wouldRead'])->toBe(12)
        ->and($history[9]['wouldRead'])->toBe(3)
        ->and(array_keys($history[0]))->toBe(['startedAt', 'scope', 'dailyCap', 'wouldRead', 'read', 'failed', 'skipped', 'status']);
});

it('never puts a name, title, document id, canary or starter into the dry run or the panel', function () {
    $f = bkFixture();
    $admin = bkAdmin();
    $run = AiBulkRun::factory()->create(['started_by' => $admin->id]);
    bkItem($run, $f['eligible'], 'read');
    $ext = bkExt(bkDoc(), 'waiting', ['bulk_run_id' => $run->id]);
    bkItem($run, MedicalDocument::query()->find($ext->document_id), null, $ext->id);

    $json = json_encode([bkQuery()->panel(), bkQuery()->dryRun($admin, 'all', 5)]);

    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect($json)->not->toContain($needle);
    }
    foreach ($f as $doc) {
        expect($json)->not->toContain($doc->id)->not->toContain($doc->subject_id);
    }
    expect($json)->not->toContain('started_by')->not->toContain((string) $ext->document_id)
        ->and($json)->not->toContain('Person ')->not->toContain('Title ');
});

// Task 3: endpoints

it('runs the whole flow over HTTP', function () {
    bkDoc();
    $admin = bkAdmin();
    $this->actingAs($admin);

    $dry = $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertOk()
        ->assertJsonPath('counts.eligible', 1)->assertJsonPath('validMinutes', 15)->assertHeader('Cache-Control', 'no-store, private');
    $token = $dry->json('token');

    $this->postJson('/settings/ai-agents/bulk', ['token' => $token, 'acknowledged' => true])->assertCreated()
        ->assertJsonPath('panel.active.state', 'running');
    $runId = DB::table('ai_bulk_runs')->value('id');

    $this->postJson('/settings/ai-agents/bulk', ['token' => $token, 'acknowledged' => true])->assertStatus(410)->assertJson(['reason' => 'expired']);

    $second = $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->json('token');
    $this->postJson('/settings/ai-agents/bulk', ['token' => $second, 'acknowledged' => true])->assertStatus(409)
        ->assertJsonPath('reason', 'active')->assertJsonPath('panel.active.id', $runId);

    $this->getJson('/settings/ai-agents/bulk')->assertOk()->assertJsonPath('active.id', $runId);
    $this->postJson("/settings/ai-agents/bulk/$runId/pause")->assertOk()->assertJsonPath('panel.active.state', 'paused');
    $this->postJson("/settings/ai-agents/bulk/$runId/pause")->assertStatus(409)->assertJsonPath('reason', 'conflict');
    $this->postJson("/settings/ai-agents/bulk/$runId/resume")->assertOk()->assertJsonPath('panel.active.status', 'active');
    $this->postJson("/settings/ai-agents/bulk/$runId/cancel")->assertOk()->assertJsonPath('panel.active', null);
    $this->postJson('/settings/ai-agents/bulk/'.Str::uuid().'/cancel')->assertNotFound();
    $this->postJson('/settings/ai-agents/bulk/nope/pause')->assertNotFound();
});

it('answers 409 stale when the eligible count changed after the dry run', function () {
    bkDoc();
    $this->actingAs(bkAdmin());
    $token = $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->json('token');
    bkDoc();

    $this->postJson('/settings/ai-agents/bulk', ['token' => $token, 'acknowledged' => true])->assertStatus(409)->assertExactJson(['reason' => 'stale']);
});

it('validates scope, cap, token and acknowledgment', function () {
    $this->actingAs(bkAdmin());

    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'everything', 'cap' => 5])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 0])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 31])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all'])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk', ['token' => 'x', 'acknowledged' => true])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk', ['token' => (string) Str::uuid()])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk', ['token' => (string) Str::uuid(), 'acknowledged' => false])->assertStatus(422);
    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 30])->assertOk();
});

it('gives every non-admin the same 403 on every bulk route and sends guests to login', function () {
    $run = (string) Str::uuid();
    $routes = [
        ['GET', '/settings/ai-agents/bulk', []],
        ['POST', '/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5]],
        ['POST', '/settings/ai-agents/bulk', ['token' => (string) Str::uuid(), 'acknowledged' => true]],
        ['POST', "/settings/ai-agents/bulk/$run/pause", []],
        ['POST', "/settings/ai-agents/bulk/$run/resume", []],
        ['POST', "/settings/ai-agents/bulk/$run/cancel", []],
    ];
    $users = Roles::make();

    foreach ($routes as [$method, $uri, $data]) {
        $bodies = [];
        foreach (['parent', 'child', 'grandparent', 'guestPreset', 'noGrants'] as $role) {
            $this->app['auth']->forgetGuards();
            $r = $this->actingAs($users[$role])->json($method, $uri, $data);
            expect($r->getStatusCode())->toBe(403, "$method $uri $role");
            $bodies[] = $r->getContent();
        }
        expect(array_unique($bodies))->toHaveCount(1, "$method $uri");

        $this->app['auth']->forgetGuards();
        expect($this->call($method, $uri, $data)->getStatusCode())->toBe(302, "$method $uri guest");
    }
});

it('refuses the POST routes without a recent confirmation', function () {
    $this->useRealSensitiveSession();
    $this->actingAs(bkAdmin());

    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertStatus(423);
    $this->postJson('/settings/ai-agents/bulk', ['token' => (string) Str::uuid(), 'acknowledged' => true])->assertStatus(423);
    $this->postJson('/settings/ai-agents/bulk/'.Str::uuid().'/cancel')->assertStatus(423);
    expect(DB::table('ai_bulk_dry_runs')->count())->toBe(0);
});

it('throttles the thirteenth dry run in a minute', function () {
    $this->actingAs(bkAdmin());

    foreach (range(1, 12) as $i) {
        $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertOk();
    }
    $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertStatus(429);
});

it('hands the page the bulk panel and config only as counts and settings', function () {
    $f = bkFixture();
    $this->actingAs(bkAdmin())->get('/settings/ai-agents')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/AiAgents', false)
        ->where('bulk.active', null)
        ->where('bulk.history', [])
        ->where('bulkConfig', ['defaultCap' => 20, 'maxCap' => 30, 'startHour' => 1, 'timezone' => 'Europe/Bratislava', 'validMinutes' => 15]));

    $r = $this->getJson('/settings/ai-agents/bulk')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->getContent())->not->toContain($f['eligible']->id);
});
