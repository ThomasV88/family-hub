<?php

use App\Access\AccessLevel;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument} */
function waitSetup(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $document = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);

    return [$user, $person, $document];
}

function waitRunUrl(MedicalSubject $person, string $document): string
{
    return "/health/people/{$person->id}/documents/{$document}/ai";
}

function waitStatusUrl(MedicalSubject $person): string
{
    return "/health/people/{$person->id}/ai-status";
}

function waitEnableCopilot(bool $first = false): void
{
    DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['enabled' => true, 'position' => 3]);
    DB::table('ai_agent_settings')->where('agent', $first ? 'codex' : 'copilot')->update(['position' => 2]);
    DB::table('ai_agent_settings')->where('agent', $first ? 'copilot' : 'codex')->update(['position' => 1]);
    app(AgentHealth::class)->recordCheck('copilot', LoginState::LoggedIn);
    app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedIn);
}

describe('status route', function () {
    it('shows a waiting run with its resume time and nothing else', function () {
        [$user, $person, $document] = waitSetup();
        $at = CarbonImmutable::now()->addMinutes(40)->startOfSecond();
        $row = MedicalAiExtraction::factory()->forDocument($document)->waiting($at)->create(['started_by' => $user->id]);

        $response = $this->actingAs($user)->getJson(waitStatusUrl($person))->assertOk();

        $entry = $response->json("documents.{$document->id}");
        expect($entry['status'])->toBe('waiting')->and($entry['extractionId'])->toBe($row->id)
            ->and(CarbonImmutable::parse($entry['availableAt'])->equalTo($at))->toBeTrue()
            ->and($entry)->not->toHaveKeys(['failureCode', 'agent', 'model']);
        expect($response->getContent())->not->toContain($document->title);
    });

    it('reports the failure code for failed runs', function (string $code) {
        [$user, $person, $document] = waitSetup();
        MedicalAiExtraction::factory()->forDocument($document)->failed($code)->create(['started_by' => $user->id]);

        $this->actingAs($user)->getJson(waitStatusUrl($person))->assertOk()
            ->assertJsonPath("documents.{$document->id}.status", 'failed')
            ->assertJsonPath("documents.{$document->id}.failureCode", $code)
            ->assertJsonMissingPath("documents.{$document->id}.availableAt");
    })->with(['quota_timeout', 'notice_required', 'quota_exhausted', 'no_agent']);

    it('names the agent and model of a ready proposal, with a null model for the default', function () {
        [$user, $person, $document] = waitSetup();
        $other = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        MedicalAiExtraction::factory()->forDocument($document)->ready(['entries' => [['fields' => ['doctor' => MedicalPrivacy::CANARY]]]], 1)
            ->create(['started_by' => $user->id, 'agent' => 'copilot', 'model' => 'gpt-test-copilot']);
        MedicalAiExtraction::factory()->forDocument($other)->ready(['entries' => []], 0)->create(['started_by' => $user->id]);

        $response = $this->actingAs($user)->getJson(waitStatusUrl($person))->assertOk()
            ->assertJsonPath("documents.{$document->id}.agent", 'copilot')
            ->assertJsonPath("documents.{$document->id}.model", 'gpt-test-copilot')
            ->assertJsonPath("documents.{$other->id}.agent", 'codex')
            ->assertJsonPath("documents.{$other->id}.model", null);
        expect($response->getContent())->not->toContain(MedicalPrivacy::CANARY);
    });

    it('answers a member without access identically with and without waiting runs', function () {
        [, $person, $document] = waitSetup();
        $stranger = User::factory()->create();
        MedicalFixtures::grantHealth($stranger);
        $shape = fn () => $this->actingAs($stranger)->getJson(waitStatusUrl($person))->getStatusCode().'|'.$this->actingAs($stranger)->getJson(waitStatusUrl($person))->json('message');
        $before = $shape();
        MedicalAiExtraction::factory()->forDocument($document)->waiting()->create();
        MedicalAiExtraction::factory()->forDocument(MedicalDocument::factory()->image()->create(['subject_id' => $person->id]))->ready(['entries' => []], 0)
            ->create(['agent' => 'copilot', 'model' => 'gpt-test-copilot']);

        expect($shape())->toBe($before)->and($before)->toStartWith('403');
    });
});

describe('waiting is the active run', function () {
    it('returns the waiting run when Run AI is pressed again', function () {
        [$user, $person, $document] = waitSetup();
        $row = MedicalAiExtraction::factory()->forDocument($document)->waiting()->create(['started_by' => $user->id]);
        DB::table('ai_notice_acks')->insert(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => 'active', 'extractionId' => $row->id]);
    });

    it('cancels a waiting run at once and clears only available_at', function () {
        [$user, $person, $document] = waitSetup();
        $row = MedicalAiExtraction::factory()->forDocument($document)->waiting()->create(['started_by' => $user->id]);
        $since = DB::table('medical_ai_extractions')->where('id', $row->id)->value('waiting_since');
        $calls = DB::table('ai_calls')->count();

        $this->actingAs($user)->postJson("/health/people/{$person->id}/ai/{$row->id}/cancel")->assertOk()->assertJson(['status' => 'cancelled']);

        $after = DB::table('medical_ai_extractions')->where('id', $row->id)->first();
        expect($after->status)->toBe('cancelled')->and($after->cancel_reason)->toBe('user')->and($after->model)->toBe('default')
            ->and($after->available_at)->toBeNull()->and($after->waiting_since)->toBe($since)
            ->and(DB::table('ai_calls')->count())->toBe($calls)
            ->and(AuditEntry::where('event', 'medical_ai_run_finished')->count())->toBe(1);
    });

    it('lets an admin with bulk runs start an interactive run while the global cap still counts them', function () {
        [$user, $person, $document] = waitSetup();
        config(['ai.limits.max_active_per_user' => 2, 'ai.limits.max_active_global' => 4]);
        $runId = (string) Str::uuid();
        foreach (range(1, 3) as $i) {
            $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
            MedicalAiExtraction::factory()->forDocument($doc)->waiting()->bulk($runId)->create(['started_by' => $user->id]);
        }

        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);

        $second = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $this->actingAs($user)->postJson(waitRunUrl($person, $second->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertExactJson(['reason' => 'busy']);
    });

    it('does not start a document cool-down after a quota failure', function (string $code) {
        [$user, $person, $document] = waitSetup();
        MedicalAiExtraction::factory()->forDocument($document)->failed($code)->create(['started_by' => $user->id]);

        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
    })->with(['quota_exhausted', 'quota_timeout']);
});

describe('availability and selection', function () {
    it('stays available while the agents only cool down, and queues', function () {
        [$user, $person, $document] = waitSetup();
        waitEnableCopilot();
        foreach (['codex', 'copilot'] as $agent) {
            app(AgentHealth::class)->recordQuotaHit($agent, CarbonImmutable::now()->addHours(2), null);
        }

        $this->actingAs($user)->getJson(waitStatusUrl($person))->assertJsonPath('available', true);
        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex', 'copilot']])->assertStatus(202);
        expect(MedicalAiExtraction::query()->sole()->agent)->toBe('codex');
    });

    it('is unavailable when no enabled agent has a usable login', function () {
        [$user, $person, $document] = waitSetup();
        waitEnableCopilot();
        app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedOut);
        app(AgentHealth::class)->recordCheck('copilot', LoginState::LoggedOut);

        $this->actingAs($user)->getJson(waitStatusUrl($person))->assertJsonPath('available', false);
        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex']])
            ->assertStatus(409)->assertJson(['reason' => 'unavailable']);
    });

    it('starts on the first runnable agent in the saved order', function () {
        [$user, $person, $document] = waitSetup();
        waitEnableCopilot(first: true);

        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex', 'copilot']])->assertStatus(202);

        expect(MedicalAiExtraction::query()->sole()->agent)->toBe('copilot')->and(DB::table('ai_calls')->sole()->agent)->toBe('copilot');
    });
});

describe('provider notice', function () {
    it('lists the enabled providers in saved order', function () {
        [$user, $person] = waitSetup();
        $page = fn (array $expected) => $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $p) => $p->where('ai.providers', $expected));

        $page(['codex']);
        waitEnableCopilot(first: true);
        $page(['copilot', 'codex']);
    });

    it('asks again once when Copilot is enabled and never when a provider is removed', function () {
        [$user, $person, $document] = waitSetup();
        $acked = fn (bool $expected) => $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $p) => $p->where('ai.noticeAcknowledged', $expected));
        DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);
        $acked(true);

        waitEnableCopilot();
        $acked(false);
        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), [])->assertStatus(409)->assertJson(['reason' => 'notice_required']);

        $this->actingAs($user)->postJson(waitRunUrl($person, $document->id), ['acknowledged' => true, 'providers' => ['codex', 'copilot']])->assertStatus(202);
        expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('providers'))->toBe('codex,copilot');
        $acked(true);

        DB::table('ai_agent_settings')->where('agent', 'codex')->update(['enabled' => false]);
        $acked(true);
    });
});
