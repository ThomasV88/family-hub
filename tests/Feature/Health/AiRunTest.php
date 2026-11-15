<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument} an Edit member, a person with consent on and an image document */
function aiSetup(bool $consent = true, string $kind = 'image'): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['ai_consent' => $consent], [[$user, AccessLevel::Edit]]);
    $document = MedicalDocument::factory()->{$kind}()->create(['subject_id' => $person->id]);

    return [$user, $person, $document];
}

function aiRunUrl(MedicalSubject $person, string $document): string
{
    return "/health/people/{$person->id}/documents/{$document}/ai";
}

/** Status and message only: the debug body of a 403 carries a stack trace with test line numbers. */
function aiShape(TestResponse $response): string
{
    return $response->getStatusCode().'|'.(string) $response->json('message');
}

function aiTables(): array
{
    return [
        'extractions' => DB::table('medical_ai_extractions')->count(),
        'calls' => DB::table('ai_calls')->count(),
        'acks' => DB::table('ai_notice_acks')->count(),
        'audit' => AuditEntry::where('event', 'medical_ai_run_requested')->count(),
        'jobs' => DB::table('jobs')->count(),
    ];
}

describe('start', function () {
    it('queues one run with call row, notice ack, audit row and an id-only job', function () {
        [$user, $person, $document] = aiSetup();

        $response = $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);

        $extraction = MedicalAiExtraction::query()->sole();
        $response->assertJson(['status' => 'queued', 'extractionId' => $extraction->id]);
        expect($extraction->status)->toBe('queued')->and($extraction->agent)->toBe('codex')->and($extraction->model)->toBeNull()
            ->and($extraction->started_by)->toBe($user->id);
        $call = DB::table('ai_calls')->sole();
        expect($call->status)->toBe('queued')->and($call->agent)->toBe('codex')->and($call->model)->toBeNull()
            ->and($call->extraction_id)->toBe($extraction->id)->and((int) $call->started_by)->toBe($user->id);
        expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->exists())->toBeTrue();
        expect(AuditEntry::where('event', 'medical_ai_run_requested')->sole()->meta)->toEqual(['subject' => $person->id, 'document' => $document->id]);

        $job = DB::table('jobs')->sole();
        expect($job->queue)->toBe('ai')->and($job->payload)->toContain($extraction->id)
            ->and($job->payload)->not->toContain($person->id)->and($job->payload)->not->toContain($document->id);
    });

    it('reports the running one instead of starting a second run', function () {
        [$user, $person, $document] = aiSetup();
        $first = $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->json('extractionId');
        $before = aiTables();

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => 'active', 'extractionId' => $first]);

        expect(aiTables())->toBe($before)->and(MedicalAiExtraction::query()->whereIn('status', ['queued', 'running'])->count())->toBe(1);
    });

    it('refuses with consent off, an unsupported kind, an unavailable service and writes nothing', function (string $case) {
        [$user, $person, $document] = aiSetup(consent: $case !== 'consent_off', kind: $case === 'unsupported' ? 'heic' : 'image');
        if ($case === 'unavailable') {
            app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedOut);
        }
        $before = aiTables();

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => $case]);

        expect(aiTables())->toBe($before);
    })->with(['consent_off', 'unsupported', 'unavailable']);

    it('treats a service of unknown state as available and one that does not answer as unavailable', function () {
        [$user, $person, $document] = aiSetup();
        expect(app(AgentHealth::class)->display())->toBe('unknown');
        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);

        [$user, $person, $document] = aiSetup();
        app(AgentHealth::class)->recordCheck('codex', LoginState::Unknown);
        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(409)->assertJson(['reason' => 'unavailable']);
    });

    it('needs the notice acknowledged the first time only', function () {
        [$user, $person, $document] = aiSetup();
        $other = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $before = aiTables();

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id))->assertStatus(409)->assertExactJson(['reason' => 'notice_required', 'providers' => ['codex']]);
        expect(aiTables())->toBe($before);

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
        expect(DB::table('ai_notice_acks')->count())->toBe(1);
        $this->actingAs($user)->postJson(aiRunUrl($person, $other->id))->assertStatus(202);
        expect(DB::table('ai_notice_acks')->count())->toBe(1);
    });

    it('answers every outsider and every bad id with the same 403, even with an invalid body', function () {
        [$user, $person, $document] = aiSetup();
        $view = User::factory()->create();
        MedicalFixtures::grantHealth($view);
        $viewPerson = MedicalFixtures::subject(['ai_consent' => true], [[$view, AccessLevel::View]]);
        $viewDoc = MedicalDocument::factory()->image()->create(['subject_id' => $viewPerson->id]);
        $admin = User::factory()->create(['is_admin' => true]);
        MedicalFixtures::grantHealth($admin);
        $hidden = MedicalFixtures::subject(['ai_consent' => true], [[User::factory()->create(), AccessLevel::Edit]]);
        $hiddenDoc = MedicalDocument::factory()->image()->create(['subject_id' => $hidden->id]);
        $before = aiTables();

        $cases = [
            'view member' => [$view, aiRunUrl($viewPerson, $viewDoc->id)],
            'admin without membership' => [$admin, aiRunUrl($person, $document->id)],
            'hidden person' => [$user, aiRunUrl($hidden, $hiddenDoc->id)],
            'hidden document' => [$user, aiRunUrl($person, $hiddenDoc->id)],
            'random uuid' => [$user, aiRunUrl($person, (string) Str::uuid7())],
            'malformed id' => [$user, aiRunUrl($person, 'abc')],
        ];
        $bodies = [];
        foreach ($cases as $label => [$actor, $url]) {
            foreach ([['acknowledged' => true, 'providers' => ['codex']], ['acknowledged' => 'garbage']] as $body) {
                $response = $this->actingAs($actor)->postJson($url, $body)->assertForbidden();
                $bodies[] = aiShape($response);
            }
        }
        expect(array_unique($bodies))->toHaveCount(1)->and(aiTables())->toBe($before);
    });

    it('rolls everything back and queues no job when the audit write fails', function () {
        [$user, $person, $document] = aiSetup();
        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $this->withoutExceptionHandling();
        $before = aiTables();

        expect(fn () => $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']]))->toThrow(RuntimeException::class);

        expect(aiTables())->toBe($before);
    });
});

describe('cancel', function () {
    function aiRun(AccessLevel $level = AccessLevel::Edit, string $state = 'queued'): array
    {
        [$user, $person, $document] = aiSetup();
        $extraction = MedicalAiExtraction::factory()->forDocument($document)->{$state}()->create(['started_by' => $user->id]);
        DB::table('ai_calls')->insert([
            'extraction_id' => $extraction->id, 'subject_id' => $person->id, 'document_id' => $document->id, 'started_by' => $user->id,
            'agent' => 'codex', 'attempt' => 1, 'status' => $state === 'ready' ? 'succeeded' : $state, 'queued_at' => now(),
        ]);

        return [$user, $person, $extraction];
    }

    function aiCancelUrl(MedicalSubject $person, string $id): string
    {
        return "/health/people/{$person->id}/ai/{$id}/cancel";
    }

    it('cancels a queued run at once and logs it', function () {
        [$user, $person, $extraction] = aiRun();

        $this->actingAs($user)->postJson(aiCancelUrl($person, $extraction->id))->assertOk()->assertExactJson(['status' => 'cancelled']);

        $row = MedicalAiExtraction::find($extraction->id);
        expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('user')->and($row->model)->toBe('default');
        $call = DB::table('ai_calls')->sole();
        expect($call->status)->toBe('cancelled')->and($call->model)->toBe('default');
        expect(AuditEntry::where('event', 'medical_ai_run_finished')->sole()->meta)->toEqual(['subject' => $person->id, 'document' => $extraction->document_id, 'status' => 'cancelled']);
    });

    it('asks a running run to stop and leaves its status alone', function () {
        [$user, $person, $extraction] = aiRun(state: 'running');

        $this->actingAs($user)->postJson(aiCancelUrl($person, $extraction->id))->assertOk()->assertExactJson(['status' => 'running']);

        $row = MedicalAiExtraction::find($extraction->id);
        expect($row->status)->toBe('running')->and($row->cancel_requested_at)->not->toBeNull();
    });

    it('changes nothing for a run that is already over', function () {
        [$user, $person, $extraction] = aiRun(state: 'ready');

        $this->actingAs($user)->postJson(aiCancelUrl($person, $extraction->id))->assertOk()->assertExactJson(['status' => 'ready']);

        expect(MedicalAiExtraction::find($extraction->id)->status)->toBe('ready')->and(AuditEntry::where('event', 'medical_ai_run_finished')->count())->toBe(0);
    });

    it('refuses a view member, a hidden extraction and bad ids with the same 403', function () {
        [$user, $person, $extraction] = aiRun();
        $view = User::factory()->create();
        MedicalFixtures::grantHealth($view);
        $viewPerson = MedicalFixtures::subject([], [[$view, AccessLevel::View]]);
        $viewDoc = MedicalDocument::factory()->pdf()->create(['subject_id' => $viewPerson->id]);
        $viewRun = MedicalAiExtraction::factory()->forDocument($viewDoc)->queued()->create();

        $bodies = [
            $this->actingAs($view)->postJson(aiCancelUrl($viewPerson, $viewRun->id)),
            $this->actingAs($user)->postJson(aiCancelUrl($person, $viewRun->id)),
            $this->actingAs($user)->postJson(aiCancelUrl($viewPerson, $viewRun->id)),
            $this->actingAs($user)->postJson(aiCancelUrl($person, (string) Str::uuid7())),
            $this->actingAs($user)->postJson(aiCancelUrl($person, 'abc')),
        ];
        $normalised = [];
        foreach ($bodies as $response) {
            $normalised[] = aiShape($response->assertForbidden());
        }
        expect(array_unique($normalised))->toHaveCount(1)->and(MedicalAiExtraction::find($viewRun->id)->status)->toBe('queued');
    });
});

describe('status', function () {
    it('gives view members statuses without counts and edit members the counts', function () {
        [$user, $person, $document] = aiSetup();
        $view = User::factory()->create();
        MedicalFixtures::grantHealth($view);
        DB::table('medical_subject_members')->insert(['subject_id' => $person->id, 'user_id' => $view->id, 'level' => AccessLevel::View->value, 'created_at' => now()]);
        $ready = MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => [['type' => 'visit', 'fields' => ['doctor' => MedicalPrivacy::CANARY]]]], 3)->create();
        DB::table('medical_ai_decisions')->insert(['extraction_id' => $ready->id, 'entry_key' => 'e1', 'decision' => 'rejected', 'decided_by' => $user->id]);
        $second = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
        MedicalAiExtraction::factory()->forDocument($second)->running()->create();

        $edit = $this->actingAs($user)->getJson("/health/people/{$person->id}/ai-status")->assertOk();
        $edit->assertJsonPath("documents.{$document->id}.status", 'ready')
            ->assertJsonPath("documents.{$document->id}.entryCount", 3)
            ->assertJsonPath("documents.{$document->id}.pending", 2)
            ->assertJsonPath("documents.{$document->id}.readyExtractionId", $ready->id)
            ->assertJsonPath("documents.{$second->id}.status", 'running')
            ->assertJsonPath('available', true);
        expect($edit->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        expect($edit->getContent())->not->toContain(MedicalPrivacy::CANARY);

        $viewer = $this->actingAs($view)->getJson("/health/people/{$person->id}/ai-status")->assertOk();
        $viewer->assertJsonPath("documents.{$document->id}.status", 'ready')->assertJsonMissingPath("documents.{$document->id}.entryCount")
            ->assertJsonMissingPath("documents.{$document->id}.pending");
        expect($viewer->getContent())->not->toContain(MedicalPrivacy::CANARY);
    });

    it('shows the newest run per document and ignores superseded ones', function () {
        [$user, $person, $document] = aiSetup();
        MedicalAiExtraction::factory()->forDocument($document)->superseded()->create(['queued_at' => now()->subDay()]);
        $ready = MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => []], 0)->create(['queued_at' => now()->subHour()]);
        $failed = MedicalAiExtraction::factory()->forDocument($document)->failed('no_output')->create(['queued_at' => now()]);

        $this->actingAs($user)->getJson("/health/people/{$person->id}/ai-status")
            ->assertJsonPath("documents.{$document->id}.status", 'failed')
            ->assertJsonPath("documents.{$document->id}.extractionId", $failed->id)
            ->assertJsonPath("documents.{$document->id}.readyExtractionId", $ready->id);
    });

    it('answers a hidden or unknown person with the same 403', function () {
        [$user] = aiSetup();
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $a = aiShape($this->actingAs($user)->getJson("/health/people/{$hidden->id}/ai-status")->assertForbidden());
        $b = aiShape($this->actingAs($user)->getJson('/health/people/'.Str::uuid7().'/ai-status')->assertForbidden());
        $c = aiShape($this->actingAs($user)->getJson('/health/people/abc/ai-status')->assertForbidden());
        expect($a)->toBe($b)->and($b)->toBe($c);
    });
});

describe('notice acknowledgment binds to the provider set shown', function () {
    it('refuses and stores nothing when a provider was enabled between the dialog and the confirm', function () {
        [$user, $person, $document] = aiSetup();
        Bus::fake();
        DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['enabled' => true]);
        $before = aiTables();

        // The dialog named OpenAI only.
        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertJson(['reason' => 'notice_required', 'providers' => ['codex', 'copilot']]);
        expect(aiTables())->toBe($before)->and(DB::table('ai_notice_acks')->where('user_id', $user->id)->exists())->toBeFalse();
        Bus::assertNothingDispatched();

        // A bare boolean, or no list, never acknowledges anything.
        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true])->assertStatus(409)->assertJson(['reason' => 'notice_required']);
        expect(aiTables())->toBe($before);

        // The re-opened dialog names the current set; confirming it stores exactly that set.
        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['copilot', 'codex']])->assertStatus(202);
        expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('providers'))->toBe('codex,copilot');
    });

    it('accepts the shown set in any order and with repeated keys', function () {
        [$user, $person, $document] = aiSetup();
        Bus::fake();
        DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['enabled' => true]);

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex', 'copilot', 'codex']])->assertStatus(202);
        expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('providers'))->toBe('codex,copilot');
    });

    it('rejects an unknown provider key in the list', function () {
        [$user, $person, $document] = aiSetup();

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['evil']])->assertStatus(422);
    });
});

describe('person page props', function () {
    it('gives an edit member canRun, the notice state and statuses with counts, never proposal content', function () {
        [$user, $person, $document] = aiSetup();
        MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => [['type' => 'visit', 'fields' => ['doctor' => MedicalPrivacy::CANARY]]]], 1)->create();

        $props = fn ($page) => $page->where('ai.canRun', true)->where('ai.available', true)->where('ai.noticeAcknowledged', false)
            ->where("ai.statuses.{$document->id}.status", 'ready')->where("ai.statuses.{$document->id}.entryCount", 1)->where("ai.statuses.{$document->id}.pending", 1);
        $response = $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $props($page));
        expect($response->getContent())->not->toContain(MedicalPrivacy::CANARY);

        DB::table('ai_notice_acks')->insert(['user_id' => $user->id]);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page->where('ai.noticeAcknowledged', true));
    });

    it('gives a view member canRun false and no counts', function () {
        [$owner, $person, $document] = aiSetup();
        $view = User::factory()->create();
        MedicalFixtures::grantHealth($view);
        DB::table('medical_subject_members')->insert(['subject_id' => $person->id, 'user_id' => $view->id, 'level' => AccessLevel::View->value, 'created_at' => now()]);
        MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => []], 1)->create();

        $this->actingAs($view)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ai.canRun', false)->where("ai.statuses.{$document->id}.status", 'ready')->missing("ai.statuses.{$document->id}.entryCount")->missing("ai.statuses.{$document->id}.pending"));
    });

    it('reports the service as unavailable while the login is expired', function () {
        [$user, $person] = aiSetup();
        app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedOut);

        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page->where('ai.available', false));
    });
});

describe('quota guard', function () {
    function aiExtra(User $user, MedicalSubject $person, string $state = 'queued'): MedicalAiExtraction
    {
        $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $factory = MedicalAiExtraction::factory()->forDocument($doc);
        $factory = match ($state) {
            'running' => $factory->running(),
            'ready' => $factory->ready(['entries' => []], 0),
            'failed' => $factory->failed(),
            'cancelled' => $factory->cancelled(),
            default => $factory,
        };

        return $factory->create(['started_by' => $user->id]);
    }

    it('refuses a start beyond the per-user active cap and writes nothing', function () {
        [$user, $person, $document] = aiSetup();
        config(['ai.limits.max_active_per_user' => 2]);
        aiExtra($user, $person);
        aiExtra($user, $person, 'running');
        $before = aiTables();

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => 'busy']);
        expect(aiTables())->toBe($before);

        // Another member is not held back by this user's runs.
        $other = User::factory()->create();
        MedicalFixtures::grantHealth($other);
        $otherPerson = MedicalFixtures::subject(['ai_consent' => true], [[$other, AccessLevel::Edit]]);
        $otherDoc = MedicalDocument::factory()->image()->create(['subject_id' => $otherPerson->id]);
        $this->actingAs($other)->postJson(aiRunUrl($otherPerson, $otherDoc->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
    });

    it('refuses a start beyond the global cap', function () {
        [$user, $person, $document] = aiSetup();
        config(['ai.limits.max_active_global' => 2]);
        $other = User::factory()->create();
        $otherPerson = MedicalFixtures::subject(['ai_consent' => true], [[$other, AccessLevel::Edit]]);
        aiExtra($other, $otherPerson);
        aiExtra($other, $otherPerson, 'running');

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => 'busy']);
    });

    it('holds a document back for the cool-down after a finished run, but not after a cancel or once it has passed', function (string $state, bool $blocked) {
        [$user, $person, $document] = aiSetup();
        MedicalAiExtraction::factory()->forDocument($document)->{$state}(...($state === 'ready' ? [['entries' => []], 0] : ($state === 'failed' ? ['invalid_output'] : [])))->create(['started_by' => $user->id]);

        $response = $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']]);

        $blocked ? $response->assertStatus(409)->assertExactJson(['reason' => 'busy']) : $response->assertStatus(202);
    })->with([['ready', true], ['failed', true], ['cancelled', false]]);

    it('does not start a cool-down after a lost worker or an unreadable file', function (string $code) {
        [$user, $person, $document] = aiSetup();
        MedicalAiExtraction::factory()->forDocument($document)->failed($code)->create(['started_by' => $user->id]);

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
    })->with(['worker_lost', 'unreadable_document', 'preflight']);

    it('allows a re-run once the cool-down has passed', function () {
        [$user, $person, $document] = aiSetup();
        MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => []], 0)->create(['started_by' => $user->id, 'finished_at' => now()->subMinutes(11)]);

        $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
    });
});

describe('failed dispatch', function () {
    it('closes the queued row when the job cannot be queued, so the document is not blocked', function () {
        [$user, $person, $document] = aiSetup();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($user)->postJson(aiRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']]))->toThrow(RuntimeException::class, 'queue down');

        $row = MedicalAiExtraction::query()->sole();
        expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('worker_lost')
            ->and(DB::table('ai_calls')->sole()->status)->toBe('failed')
            ->and(MedicalAiExtraction::query()->whereIn('status', ['queued', 'running'])->count())->toBe(0);
    });
});
