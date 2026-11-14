<?php

use App\Access\AccessLevel;
use App\Medical\Ai\FailureCode;
use App\Medical\Queries\AiJobQuery;
use App\Models\AiBulkRun;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
