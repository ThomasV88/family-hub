<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Models\AiAgentSetting;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

/*
 * Phase 9 end to end (09-19), against fake CLIs only: interactive fallback from Codex to Copilot, a run that waits out a
 * usage limit and resumes, the 24 h cap, the provider acknowledgment, and a bulk run from dry run to completion. Accepted
 * data and the record tables are never changed by any of it.
 */
const E2E_QUOTA_CODEX = 'You have hit your usage limit. Try again later.';
const E2E_QUOTA_COPILOT = "You've hit your session rate limit. Try again in 3 hours.";

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    Queue::fake();
    $this->scripts = [];
    $this->root = sys_get_temp_dir().'/e2e9-work-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
        'ai.limits.document_cooldown_minutes' => 0,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach ($this->scripts as $f) {
        @unlink($f);
    }
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

/** @return array<string, mixed> */
function e2ePayload(string $marker = 'Reading'): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [], 'medications' => [['name' => $marker.' medication', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

function e2eBin(object $test, string $agent, string $script): string
{
    $test->scripts[] = $script;
    config(["ai.{$agent}.binary" => $script]);

    return $script;
}

function e2eCopilotOn(): void
{
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
}

/** @return array{user: User, subject: mixed, doc: MedicalDocument} a member with Health edit, a person with consent and one image document */
function e2eMember(): array
{
    $user = User::factory()->create(['name' => 'Mona Member']);
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan']);
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes');

    return compact('user', 'subject', 'doc');
}

/** Presses Run AI over HTTP and returns the new extraction id (the queue is faked, nothing runs yet). */
function e2eStart(object $test, array $m): string
{
    return (string) $test->actingAs($m['user'])->postJson("/health/people/{$m['subject']->id}/documents/{$m['doc']->id}/ai", ['acknowledged' => true, 'providers' => (DB::table('ai_agent_settings')->where('enabled', true)->pluck('agent')->all() ?: ['codex'])])
        ->assertStatus(202)->json('extractionId');
}

function e2eRun(string $id): void
{
    (new RunExtractionJob($id))->handle();
}

function e2eStatus(object $test, array $m): array
{
    app('auth')->forgetGuards();

    return $test->actingAs($m['user'])->getJson("/health/people/{$m['subject']->id}/ai-status")->assertOk()->json("documents.{$m['doc']->id}");
}

/** @return list<array<string, mixed>> */
function e2eCallRows(object $test, User $admin, string $query = ''): array
{
    app('auth')->forgetGuards();
    $rows = [];
    $test->actingAs($admin)->get('/settings/ai-calls'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$rows) {
        $rows = $page->toArray()['props']['calls']['rows'];
    });

    return $rows;
}

it('falls back from Codex to Copilot on a usage limit and shows both attempts to the admin', function () {
    e2eCopilotOn();
    $m = e2eMember();
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    e2eBin($this, 'codex', FakeCodex::quota(E2E_QUOTA_CODEX));
    e2eBin($this, 'copilot', FakeCopilot::replying(json_encode(e2ePayload())));

    $id = e2eStart($this, $m);
    expect(DB::table('ai_notice_acks')->where('user_id', $m['user']->id)->value('providers'))->toContain('copilot');
    e2eRun($id);

    $status = e2eStatus($this, $m);
    expect($status['status'])->toBe('ready')->and($status['agent'])->toBe('copilot');

    $rows = collect(e2eCallRows($this, $admin))->keyBy('agent');
    expect($rows)->toHaveCount(2)
        ->and($rows['codex']['quota'])->toBeTrue()->and($rows['codex']['status'])->toBe('failed')
        ->and($rows['copilot']['quota'])->toBeFalse()->and($rows['copilot']['status'])->toBe('succeeded');
});

it('waits when every agent is limited, resumes after the reset and completes with provenance', function () {
    e2eCopilotOn();
    $m = e2eMember();
    e2eBin($this, 'codex', FakeCodex::quota(E2E_QUOTA_CODEX));
    e2eBin($this, 'copilot', FakeCopilot::quota(E2E_QUOTA_COPILOT));

    $id = e2eStart($this, $m);
    e2eRun($id);

    $status = e2eStatus($this, $m);
    expect($status['status'])->toBe('waiting')->and($status['availableAt'])->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    // Time passes beyond the earliest reset (Codex ladder 60 minutes); Codex is back and answers.
    $this->travelTo(now()->addHours(2));
    expect(Artisan::call('ai:resume-waiting'))->toBe(0);
    expect(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('queued');
    e2eBin($this, 'codex', FakeCodex::succeeding(json_encode(e2ePayload())));
    e2eRun($id);

    expect(e2eStatus($this, $m)['status'])->toBe('ready');
    app('auth')->forgetGuards();
    $this->actingAs($m['user'])->get("/health/people/{$m['subject']->id}/documents/{$m['doc']->id}/review")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('provenance.agent', 'codex')->where('newerWaiting', null));
    // The waiting history is kept, the run is not waiting any more.
    $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($row->available_at)->toBeNull()->and($row->waiting_since)->not->toBeNull();
});

it('fails a run that has waited past the 24 hour cap as quota_timeout', function () {
    $m = e2eMember();
    e2eBin($this, 'codex', FakeCodex::quota(E2E_QUOTA_CODEX));
    $id = e2eStart($this, $m);
    e2eRun($id);
    expect(e2eStatus($this, $m)['status'])->toBe('waiting');

    DB::table('medical_ai_extractions')->where('id', $id)->update(['waiting_since' => now()->subHours(25)]);
    expect(Artisan::call('ai:reap-stuck'))->toBe(0);

    $status = e2eStatus($this, $m);
    expect($status['status'])->toBe('failed')->and($status['failureCode'])->toBe('quota_timeout')
        ->and(DB::table('medical_ai_extractions')->where('id', $id)->value('available_at'))->toBeNull();
});

it('never calls Copilot for a run whose starter acknowledged Codex only, even when Copilot is enabled while it waits', function () {
    $m = e2eMember();
    e2eBin($this, 'codex', FakeCodex::quota(E2E_QUOTA_CODEX));
    $id = e2eStart($this, $m);
    expect(DB::table('ai_notice_acks')->where('user_id', $m['user']->id)->value('providers'))->not->toContain('copilot');
    e2eRun($id);
    expect(e2eStatus($this, $m)['status'])->toBe('waiting');

    e2eCopilotOn();
    $record = sys_get_temp_dir().'/e2e9-rec-'.bin2hex(random_bytes(4));
    e2eBin($this, 'copilot', FakeCopilot::recording($record, json_encode(e2ePayload())));
    $this->scripts[] = $record;
    $this->travelTo(now()->addHours(2));
    Artisan::call('ai:resume-waiting');
    e2eRun($id);

    expect(file_exists($record))->toBeFalse()
        ->and(DB::table('ai_calls')->where('extraction_id', $id)->where('agent', 'copilot')->count())->toBe(0)
        ->and(DB::table('medical_ai_extractions')->where('id', $id)->value('status'))->toBe('waiting');
});

/** @return array<string, mixed> the record tables and every decision, for equality before and after */
function e2eRecords(): array
{
    return [
        'counts' => [DB::table('medical_visits')->count(), DB::table('medical_medications')->count(), DB::table('medical_allergies')->count(),
            DB::table('medical_vaccinations')->count(), DB::table('medical_documents')->count()],
        'decisions' => DB::table('medical_ai_decisions')->orderBy('extraction_id')->orderBy('entry_key')->get()->all(),
    ];
}

it('runs a bulk re-read from dry run to completion without touching accepted data or any record table', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 00:30', 'Europe/Bratislava')->utc());
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    e2eBin($this, 'codex', FakeCodex::succeeding(json_encode(e2ePayload('Bulk'))));

    $docs = [];
    foreach ([1, 2, 3] as $i) {
        $subject = MedicalFixtures::subject(['name' => 'Bulk Person '.$i.' '.MedicalPrivacy::CANARY, 'ai_consent' => true]);
        $docs[$i] = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Title '.$i.' '.MedicalPrivacy::CANARY]);
        Storage::disk('medical')->put($docs[$i]->path, 'jpeg-bytes');
    }
    // Document 3 holds accepted data, on a superseded proposal only; a newer ready proposal exists without decisions.
    $old = MedicalAiExtraction::factory()->forDocument($docs[3])->superseded(e2ePayload('Accepted'))->create();
    MedicalAiExtraction::factory()->forDocument($docs[3])->ready(e2ePayload('Newer'), 1)->create();
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $old->id, 'entry_key' => 'medication-0', 'decision' => 'accepted',
        'record_type' => 'medication', 'record_id' => (string) Str::uuid(), 'decided_by' => $admin->id]);
    $before = e2eRecords();
    $acceptedProposal = DB::table('medical_ai_extractions')->where('document_id', $docs[3]->id)->orderBy('id')->get()->all();

    $this->actingAs($admin);
    $dry = $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertOk()->assertJsonPath('counts.eligible', 2);
    $this->postJson('/settings/ai-agents/bulk', ['token' => $dry->json('token'), 'acknowledged' => true, 'providers' => ['codex']])->assertCreated();
    $runId = (string) DB::table('ai_bulk_runs')->value('id');

    $tick = function (string $local) {
        $this->travelTo(CarbonImmutable::parse("2026-03-10 $local", 'Europe/Bratislava')->utc());
        Artisan::call('ai:bulk-tick');

        return trim(Artisan::output());
    };

    expect($tick('00:40'))->toBe('enqueued=0 reconciled=0 completed=0'); // before the off-peak hour
    expect($tick('01:05'))->toBe('enqueued=1 reconciled=0 completed=0');
    $first = (string) DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->value('id');
    e2eRun($first);
    expect($tick('01:20'))->toBe('enqueued=1 reconciled=1 completed=0');
    $second = (string) DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->where('id', '!=', $first)->value('id');
    e2eRun($second);
    expect($tick('01:35'))->toBe('enqueued=0 reconciled=1 completed=1');

    $run = DB::table('ai_bulk_runs')->where('id', $runId)->first();
    expect($run->status)->toBe('completed')
        ->and(DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)->pluck('outcome')->all())->toBe(['read', 'read'])
        ->and(DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->whereIn('status', ['ready'])->count())->toBe(2)
        ->and(DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->where('document_id', $docs[3]->id)->count())->toBe(0);

    // Accepted data and every record table are exactly as before; the accepted document's proposals are untouched.
    expect(e2eRecords())->toEqual($before)
        ->and(DB::table('medical_ai_extractions')->where('document_id', $docs[3]->id)->orderBy('id')->get()->all())->toEqual($acceptedProposal);

    // Settings > AI calls: bulk rows are there, with no person, and the person filter finds none of them.
    $rows = e2eCallRows($this, $admin);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['bulk'])->toBeTrue()->and($row['person'])->toBeNull();
    }
    // The call rows say nothing about which people bulk processed. (The filter dropdown lists every person, as Settings > Members
    // does under the Phase 8 admin exception; that list is the same whether or not a bulk run exists.)
    $calls = json_encode($rows);
    expect($calls)->not->toContain('Bulk Person')->not->toContain(MedicalPrivacy::CANARY)->not->toContain('Title ');
    foreach ($docs as $doc) {
        expect(e2eCallRows($this, $admin, '?person='.$doc->subject_id))->toBe([]);
    }
});

it('refuses a bulk job for a document that gained an accepted decision on a superseded proposal after it was queued', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 00:30', 'Europe/Bratislava')->utc());
    $admin = User::factory()->admin()->create();
    e2eBin($this, 'codex', FakeCodex::succeeding(json_encode(e2ePayload('Bulk'))));
    $subject = MedicalFixtures::subject(['ai_consent' => true]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create();
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes');

    $this->actingAs($admin);
    $dry = $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertOk()->assertJsonPath('counts.eligible', 1);
    $this->postJson('/settings/ai-agents/bulk', ['token' => $dry->json('token'), 'acknowledged' => true, 'providers' => ['codex']])->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 01:05', 'Europe/Bratislava')->utc());
    Artisan::call('ai:bulk-tick');
    $id = (string) DB::table('medical_ai_extractions')->whereNotNull('bulk_run_id')->value('id');

    // A decision appears on a superseded proposal after the enqueue.
    $old = MedicalAiExtraction::factory()->forDocument($doc)->superseded(e2ePayload('Accepted'))->create();
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $old->id, 'entry_key' => 'medication-0', 'decision' => 'accepted',
        'record_type' => 'medication', 'record_id' => (string) Str::uuid(), 'decided_by' => $admin->id]);
    $before = e2eRecords();

    e2eRun($id);

    $row = DB::table('medical_ai_extractions')->where('id', $id)->first();
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('decided')->and($row->payload)->toBeNull()
        ->and(e2eRecords())->toEqual($before)
        ->and(DB::table('medical_ai_extractions')->where('id', $old->id)->value('status'))->toBe('superseded');
});
