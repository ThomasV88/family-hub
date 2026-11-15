<?php

use App\Access\AccessLevel;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Queries\AiJobQuery;
use App\Models\AiAgentSetting;
use App\Models\AiBulkRun;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\Support\MedicalFixtures;

/** A person with consent, one document and a claimed (running) run with its running call row. */
function wrSetup(array $run = []): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create();
    $row = MedicalAiExtraction::factory()->forDocument($doc)->create($run + ['started_by' => $user->id, 'status' => 'running', 'started_at' => now()]);
    AiCall::factory()->running()->create(['extraction_id' => $row->id, 'subject_id' => $subject->id, 'document_id' => $doc->id, 'started_by' => $user->id]);

    return ['user' => $user, 'subject' => $subject, 'doc' => $doc, 'run' => $row];
}

function wrRow(string $id): object
{
    return DB::table('medical_ai_extractions')->where('id', $id)->first();
}

function wrCalls(string $id): array
{
    return DB::table('ai_calls')->where('extraction_id', $id)->orderBy('attempt')->get()->all();
}

it('reuses the claimed call row for the same agent and replaces it for another', function () {
    $s = wrSetup();
    $jobs = app(AiJobQuery::class);

    expect($jobs->beginAttempt($s['run']->id, 'codex', null))->toBe(1)
        ->and(wrCalls($s['run']->id))->toHaveCount(1);

    $s2 = wrSetup();
    $bulk = AiBulkRun::factory()->create();
    expect($jobs->beginAttempt($s2['run']->id, 'copilot', $bulk->id))->toBe(2);
    $calls = wrCalls($s2['run']->id);
    expect($calls)->toHaveCount(2)
        ->and([$calls[0]->agent, $calls[0]->status, $calls[0]->attempt])->toBe(['codex', 'cancelled', 1])
        ->and([$calls[1]->agent, $calls[1]->status, $calls[1]->attempt, $calls[1]->bulk_run_id])->toBe(['copilot', 'running', 2, $bulk->id]);
});

it('closes only the call row on closeAttempt and numbers later attempts after it', function () {
    $s = wrSetup();
    $jobs = app(AiJobQuery::class);
    $jobs->beginAttempt($s['run']->id, 'codex', null);

    $jobs->closeAttempt($s['run']->id, 'failed', FailureCode::QuotaExhausted, 'gpt-x', 1200);

    $call = wrCalls($s['run']->id)[0];
    expect($call->status)->toBe('failed')->and($call->failure_code)->toBe('quota_exhausted')->and($call->model)->toBe('gpt-x')->and($call->duration_ms)->toBe(1200)
        ->and(wrRow($s['run']->id)->status)->toBe('running');

    expect($jobs->beginAttempt($s['run']->id, 'codex', null))->toBe(2)->and(wrCalls($s['run']->id))->toHaveCount(2);
});

it('parks a running row once and keeps the first waiting_since', function () {
    $s = wrSetup();
    $jobs = app(AiJobQuery::class);
    $first = CarbonImmutable::now()->addMinutes(30);

    expect($jobs->parkWaiting($s['run']->id, $first))->toBeTrue();
    $row = wrRow($s['run']->id);
    expect($row->status)->toBe('waiting')->and($row->available_at)->not->toBeNull()->and($row->waiting_since)->not->toBeNull()
        ->and(wrCalls($s['run']->id)[0]->status)->toBe('cancelled');
    $since = $row->waiting_since;

    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'running', 'available_at' => null]);
    expect($jobs->parkWaiting($s['run']->id, CarbonImmutable::now()->addHour()))->toBeTrue()
        ->and(wrRow($s['run']->id)->waiting_since)->toBe($since)
        ->and($jobs->waitingSince($s['run']->id)?->equalTo(CarbonImmutable::parse($since)))->toBeTrue();
});

it('does not park a cancel-requested or a finished row', function () {
    $s = wrSetup(['cancel_requested_at' => now()]);
    $jobs = app(AiJobQuery::class);

    expect($jobs->parkWaiting($s['run']->id, CarbonImmutable::now()->addHour()))->toBeFalse()
        ->and(wrRow($s['run']->id)->status)->toBe('running');

    $t = wrSetup(['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => now()]);
    expect($jobs->parkWaiting($t['run']->id, CarbonImmutable::now()->addHour()))->toBeFalse();
});

it('resumes only due rows, resets queued_at, clears available_at and keeps waiting_since', function () {
    $jobs = app(AiJobQuery::class);
    $due = wrSetup();
    $later = wrSetup();
    $jobs->parkWaiting($due['run']->id, CarbonImmutable::now()->addMinutes(10));
    $jobs->parkWaiting($later['run']->id, CarbonImmutable::now()->addHours(2));
    $since = wrRow($due['run']->id)->waiting_since;
    DB::table('medical_ai_extractions')->where('id', $due['run']->id)->update(['available_at' => now()->subMinute(), 'queued_at' => now()->subHours(5)]);

    $resumed = $jobs->resumeDue();

    $row = wrRow($due['run']->id);
    expect($resumed)->toBe([['id' => $due['run']->id, 'bulk_run_id' => null]])
        ->and($row->status)->toBe('queued')->and($row->available_at)->toBeNull()->and($row->waiting_since)->toBe($since)
        ->and(CarbonImmutable::parse($row->queued_at)->gt(now()->subMinute()))->toBeTrue()
        ->and(wrRow($later['run']->id)->status)->toBe('waiting');
});

it('selects due rows with SKIP LOCKED and a limit, and rewaits a failed dispatch', function () {
    $jobs = app(AiJobQuery::class);
    $s = wrSetup();
    $jobs->parkWaiting($s['run']->id, CarbonImmutable::now()->addMinutes(5));
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['available_at' => now()->subMinute()]);

    DB::enableQueryLog();
    $jobs->resumeDue(5);
    $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();
    expect($sql)->toContain('for update skip locked')->and($sql)->toContain('limit');

    $jobs->rewait($s['run']->id);
    $row = wrRow($s['run']->id);
    expect($row->status)->toBe('waiting')
        ->and(CarbonImmutable::parse($row->available_at)->between(now()->addSeconds(30), now()->addMinutes(2)))->toBeTrue();

    $jobs->rewait($s['run']->id);
    expect(wrRow($s['run']->id)->status)->toBe('waiting');
});

it('fails waiting rows past the cap as quota_timeout with an audit row and leaves younger ones alone', function () {
    $jobs = app(AiJobQuery::class);
    $old = wrSetup();
    $young = wrSetup();
    foreach ([$old, $young] as $s) {
        $jobs->parkWaiting($s['run']->id, CarbonImmutable::now()->addHour());
    }
    DB::table('medical_ai_extractions')->where('id', $old['run']->id)->update(['waiting_since' => now()->subHours(24)->subMinutes(6)]);
    DB::table('medical_ai_extractions')->where('id', $young['run']->id)->update(['waiting_since' => now()->subHours(23)]);
    $since = wrRow($old['run']->id)->waiting_since;

    expect($jobs->reapStuck(780, 6))->toBe(1);

    $row = wrRow($old['run']->id);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('quota_timeout')->and($row->available_at)->toBeNull()
        ->and($row->waiting_since)->toBe($since)->and($row->model)->toBe('default')
        ->and(wrRow($young['run']->id)->status)->toBe('waiting');
    $audit = DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->actor_id)->toBe($old['user']->id)
        ->and(json_decode($audit[0]->meta, true))->toEqual(['subject' => $old['subject']->id, 'document' => $old['doc']->id, 'status' => 'failed']);
});

it('claims a resumed row without an open call row and reports its bulk run id', function () {
    $jobs = app(AiJobQuery::class);
    $bulk = AiBulkRun::factory()->create();
    $s = wrSetup(['bulk_run_id' => $bulk->id]);
    $jobs->parkWaiting($s['run']->id, CarbonImmutable::now()->addMinutes(5));
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['available_at' => now()->subMinute()]);
    $jobs->resumeDue();

    $claim = $jobs->claim($s['run']->id);

    expect($claim['bulk_run_id'])->toBe($bulk->id)->and($claim['started_by'])->toBe($s['user']->id)
        ->and(wrRow($s['run']->id)->status)->toBe('running')
        ->and(array_map(fn ($c) => $c->status, wrCalls($s['run']->id)))->toBe(['cancelled']);
});

it('reads provider acknowledgments and a cancelled bulk run', function () {
    $jobs = app(AiJobQuery::class);
    $user = User::factory()->create();

    expect($jobs->ackedProviders($user->id))->toBe([])->and($jobs->ackedProviders(null))->toBe([]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex,copilot']);
    expect($jobs->ackedProviders($user->id))->toBe(['codex', 'copilot']);

    $active = AiBulkRun::factory()->create();
    expect($jobs->bulkRunCancelled($active->id))->toBeFalse();
    DB::table('ai_bulk_runs')->where('id', $active->id)->update(['status' => 'cancelled']);
    expect($jobs->bulkRunCancelled($active->id))->toBeTrue();
});

// ---- the job: waiting, notice acknowledgment, bulk rule, resumer ----

function wrPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [], 'medications' => [['name' => 'Enalapril', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

/**
 * A queued run (interactive, or bulk for an admin who is not a member of the person), its call row and the starter's
 * acknowledgment of $acks.
 */
function wrQueued(string $acks = 'codex', bool $bulk = false): array
{
    if ($bulk) {
        $user = User::factory()->admin()->create();
        $subject = MedicalFixtures::subject(['ai_consent' => true]);
    } else {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::Edit);
        $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    }
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan']);
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes');
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => $acks]);
    $bulkRun = $bulk ? AiBulkRun::factory()->create(['started_by' => $user->id, 'providers' => $acks]) : null;
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id, 'bulk_run_id' => $bulkRun?->id]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $subject->id, 'document_id' => $doc->id, 'started_by' => $user->id, 'bulk_run_id' => $bulkRun?->id]);

    return ['user' => $user, 'subject' => $subject, 'doc' => $doc, 'run' => $run, 'bulk' => $bulkRun];
}

function wrRunJob(string $id): void
{
    (new RunExtractionJob($id))->handle();
}

function wrCopilotOn(): void
{
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
}

function wrBin(string $agent, string $script): string
{
    $GLOBALS['wr_scripts'][] = $script;
    config(["ai.{$agent}.binary" => $script]);

    return $script;
}

beforeEach(function () {
    Storage::fake('medical');
    $this->root = sys_get_temp_dir().'/wr-work-'.strtolower((string) Str::ulid());
    $GLOBALS['wr_scripts'] = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['wr_scripts'] as $f) {
        @unlink($f);
    }
    $GLOBALS['wr_scripts'] = [];
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

it('parks the run on the other agent cooldown when the first hits its limit and the second is cooling down', function () {
    $s = wrQueued('codex,copilot');
    wrCopilotOn();
    (new AgentHealth)->recordQuotaHit('copilot', CarbonImmutable::now()->addMinutes(40), null);
    wrBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));

    wrRunJob($s['run']->id);

    $row = wrRow($s['run']->id);
    $calls = wrCalls($s['run']->id);
    expect($row->status)->toBe('waiting')->and($row->waiting_since)->not->toBeNull()
        ->and(CarbonImmutable::parse($row->available_at)->between(now()->addMinutes(39), now()->addMinutes(41)))->toBeTrue()
        ->and(array_map(fn ($c) => [$c->agent, $c->status, $c->failure_code], $calls))->toBe([['codex', 'failed', 'quota_exhausted']])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->count())->toBe(0)
        ->and(glob($this->root.'/*'))->toBe([]);
});

it('fails at once when the only agent will not reset within the cap, and waits when it will', function () {
    $far = wrQueued();
    (new AgentHealth)->recordQuotaHit('codex', CarbonImmutable::now()->addHours(30), null);
    wrRunJob($far['run']->id);
    expect(wrRow($far['run']->id)->status)->toBe('failed')->and(wrRow($far['run']->id)->failure_code)->toBe('quota_exhausted');

    $near = wrQueued();
    (new AgentHealth)->recordQuotaHit('codex', CarbonImmutable::now()->addHours(2), null);
    wrRunJob($near['run']->id);
    expect(wrRow($near['run']->id)->status)->toBe('waiting');
});

it('measures the cap from the first park', function () {
    $s = wrQueued();
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['waiting_since' => now()->subHours(23)]);
    (new AgentHealth)->recordQuotaHit('codex', CarbonImmutable::now()->addHours(2), null);

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->toBe('failed')->and(wrRow($s['run']->id)->failure_code)->toBe('quota_exhausted');
});

it('fails as no_agent when no enabled agent has a usable login and nothing was tried', function () {
    $s = wrQueued('codex,copilot');
    wrCopilotOn();
    foreach (['codex', 'copilot'] as $agent) {
        (new AgentHealth)->recordCheck($agent, LoginState::LoggedOut);
    }

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->failure_code)->toBe('no_agent');
});

it('never gives the document to a provider the starter has not acknowledged, and parks on the acknowledged one', function () {
    $s = wrQueued('codex');
    wrCopilotOn();
    $record = sys_get_temp_dir().'/wr-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['wr_scripts'][] = $record;
    wrBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
    wrBin('copilot', FakeCopilot::recording($record, json_encode(wrPayload())));

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->toBe('waiting')->and(file_exists($record))->toBeFalse()
        ->and(array_map(fn ($c) => $c->agent, wrCalls($s['run']->id)))->toBe(['codex']);
});

it('holds a resumed run to the acknowledgment at resume time, and lets it use Copilot once acknowledged', function () {
    Queue::fake();
    $record = sys_get_temp_dir().'/wr-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['wr_scripts'][] = $record;
    wrBin('copilot', FakeCopilot::recording($record, json_encode(wrPayload())));
    wrBin('codex', FakeCodex::recording($record.'-codex', json_encode(wrPayload())));
    $GLOBALS['wr_scripts'][] = $record.'-codex';

    // Waiting while Codex was the agent; since then Copilot was enabled and Codex disabled.
    $s = wrQueued('codex');
    DB::table('ai_calls')->where('extraction_id', $s['run']->id)->update(['status' => 'cancelled', 'finished_at' => now()]);
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'waiting', 'available_at' => now()->subMinute(), 'waiting_since' => now()->subHour()]);
    wrCopilotOn();
    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => false]);

    Artisan::call('ai:resume-waiting');
    expect(wrRow($s['run']->id)->status)->toBe('queued');
    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->toBe('failed')->and(wrRow($s['run']->id)->failure_code)->toBe('notice_required')
        ->and(file_exists($record))->toBeFalse()->and(file_exists($record.'-codex'))->toBeFalse();

    // The member acknowledges both providers: a new, resumed run may use Copilot.
    DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->update(['providers' => 'codex,copilot']);
    $again = MedicalAiExtraction::factory()->forDocument($s['doc'])->create(['started_by' => $s['user']->id, 'status' => 'waiting', 'available_at' => now()->subMinute(), 'waiting_since' => now()->subHour()]);
    Artisan::call('ai:resume-waiting');
    wrRunJob($again->id);

    expect(wrRow($again->id)->status)->toBe('ready')->and(wrRow($again->id)->agent)->toBe('copilot');
});

it('uses the starting admin acknowledgment for a bulk run and never Copilot beyond it', function () {
    config(['ai.bulk.fallback_agents' => true]);
    $s = wrQueued('codex', true);
    wrCopilotOn();
    $record = sys_get_temp_dir().'/wr-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['wr_scripts'][] = $record;
    wrBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
    wrBin('copilot', FakeCopilot::recording($record, json_encode(wrPayload())));

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->toBe('waiting')->and(file_exists($record))->toBeFalse()
        ->and(wrCalls($s['run']->id)[0]->bulk_run_id)->toBe($s['bulk']->id);
});

it('keeps a bulk row on the run\'s frozen providers when the admin later acknowledges more', function () {
    config(['ai.bulk.fallback_agents' => true]);
    $s = wrQueued('codex', true);
    wrCopilotOn();
    // A later interactive acknowledgment of both providers by the same admin.
    DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->update(['providers' => 'codex,copilot']);
    $record = sys_get_temp_dir().'/wr-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['wr_scripts'][] = $record;
    wrBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
    wrBin('copilot', FakeCopilot::recording($record, json_encode(wrPayload())));

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->not->toBe('ready')->and(file_exists($record))->toBeFalse();
});

it('runs a bulk row for an admin who is not a member of the person', function () {
    $s = wrQueued('codex', true);
    wrBin('codex', FakeCodex::succeeding(json_encode(wrPayload())));

    wrRunJob($s['run']->id);

    $row = wrRow($s['run']->id);
    expect($row->status)->toBe('ready')->and($row->agent)->toBe('codex')->and(wrCalls($s['run']->id)[0]->bulk_run_id)->toBe($s['bulk']->id);
});

it('cancels a bulk row before any spawn when the admin was demoted, the run cancelled or consent withdrawn', function (string $case, string $reason) {
    $s = wrQueued('codex', true);
    $record = sys_get_temp_dir().'/wr-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['wr_scripts'][] = $record;
    wrBin('codex', FakeCodex::recording($record, json_encode(wrPayload())));
    match ($case) {
        'demoted' => DB::table('users')->where('id', $s['user']->id)->update(['is_admin' => false]),
        'cancelled' => DB::table('ai_bulk_runs')->where('id', $s['bulk']->id)->update(['status' => 'cancelled']),
        'consent' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]),
    };

    wrRunJob($s['run']->id);

    $row = wrRow($s['run']->id);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe($reason)->and(file_exists($record))->toBeFalse();
})->with([['demoted', 'access'], ['cancelled', 'user'], ['consent', 'consent']]);

it('finishes a bulk result as cancelled(decided) when a proposal with accepted entries exists', function () {
    $s = wrQueued('codex', true);
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(wrPayload(), 1)->create(['started_by' => $s['user']->id]);
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $old->id, 'entry_key' => 'medication-0', 'decision' => 'accepted',
        'record_type' => 'medication', 'record_id' => '1', 'decided_by' => $s['user']->id]);
    wrBin('codex', FakeCodex::succeeding(json_encode(wrPayload())));

    wrRunJob($s['run']->id);

    expect(wrRow($s['run']->id)->status)->toBe('cancelled')->and(wrRow($s['run']->id)->cancel_reason)->toBe('decided')
        ->and(wrRow($old->id)->status)->toBe('ready')
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->count())->toBe(1);
});

it('resumes due rows on the ai queue, bulk rows on ai-bulk, and prints a number only', function () {
    Queue::fake();
    $doc = MedicalDocument::factory()->image()->forSubject(MedicalFixtures::subject(['ai_consent' => true]))->create();
    $interactive = MedicalAiExtraction::factory()->forDocument($doc)->waiting(now()->subMinute())->create();
    $bulkRun = AiBulkRun::factory()->create();
    $doc2 = MedicalDocument::factory()->image()->forSubject(MedicalFixtures::subject(['ai_consent' => true]))->create();
    $bulk = MedicalAiExtraction::factory()->forDocument($doc2)->waiting(now()->subMinute())->bulk($bulkRun->id)->create();

    expect(Artisan::call('ai:resume-waiting'))->toBe(0)->and(trim(Artisan::output()))->toBe('2');

    Queue::assertPushed(RunExtractionJob::class, fn ($j) => $j->extractionId === $interactive->id && $j->queue === 'ai' && $j->connection === 'database');
    Queue::assertPushed(RunExtractionJob::class, fn ($j) => $j->extractionId === $bulk->id && $j->queue === 'ai-bulk');
    expect(wrRow($interactive->id)->status)->toBe('queued')->and(wrRow($bulk->id)->status)->toBe('queued');
});

it('puts a row back to waiting when its dispatch fails', function () {
    $doc = MedicalDocument::factory()->image()->forSubject(MedicalFixtures::subject(['ai_consent' => true]))->create();
    $row = MedicalAiExtraction::factory()->forDocument($doc)->waiting(now()->subMinute())->create();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $bus);

    Artisan::call('ai:resume-waiting');

    $after = wrRow($row->id);
    expect(trim(Artisan::output()))->toBe('1')->and($after->status)->toBe('waiting')
        ->and(CarbonImmutable::parse($after->available_at)->between(now()->addSeconds(30), now()->addMinutes(2)))->toBeTrue();
});
