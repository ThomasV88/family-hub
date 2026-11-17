<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AdminAiBulkQuery;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('medical');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** @return array{0: User, 1: mixed, 2: MedicalDocument} an Edit member, a consenting person and an image document */
function txtNoticeSetup(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);

    return [$user, $person, $doc];
}

function txtNoticeUrl($person, MedicalDocument $doc): string
{
    return "/health/people/{$person->id}/documents/{$doc->id}/ai";
}

function txtNoticeAck(User $user, ?string $textAt = null, string $providers = 'codex'): void
{
    DB::table('ai_notice_acks')->upsert(
        [['user_id' => $user->id, 'acknowledged_at' => now(), 'providers' => $providers, 'text_notice_acked_at' => $textAt]],
        ['user_id'],
        ['acknowledged_at', 'providers', 'text_notice_acked_at'],
    );
}

function txtNoticeTextAt(User $user): mixed
{
    return DB::table('ai_notice_acks')->where('user_id', $user->id)->value('text_notice_acked_at');
}

describe('single runs', function () {
    it('keeps the old acknowledgment valid while full-text reading is off', function () {
        config(['ai.ocr.enabled' => false]);
        [$user, $person, $doc] = txtNoticeSetup();
        txtNoticeAck($user);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), [])->assertStatus(202);

        expect(DB::table('medical_ai_extractions')->sole()->mode)->toBe('entries')
            ->and(txtNoticeTextAt($user))->toBeNull();
    });

    it('asks a member with only the old acknowledgment once more and stores the new one', function () {
        config(['ai.ocr.enabled' => true]);
        [$user, $person, $doc] = txtNoticeSetup();
        txtNoticeAck($user);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), [])
            ->assertStatus(409)->assertJson(['reason' => 'notice_required', 'providers' => ['codex'], 'textNotice' => true]);
        expect(DB::table('medical_ai_extractions')->count())->toBe(0)->and(txtNoticeTextAt($user))->toBeNull();

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);
        expect(DB::table('medical_ai_extractions')->sole()->mode)->toBe('full')->and(txtNoticeTextAt($user))->not->toBeNull();

        // The next run needs nothing more (a second document so the first run does not block it).
        $second = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $this->actingAs($user)->postJson(txtNoticeUrl($person, $second), [])->assertStatus(202);
    });

    it('does not accept the acknowledgment without the shown provider set', function () {
        config(['ai.ocr.enabled' => true]);
        [$user, $person, $doc] = txtNoticeSetup();
        txtNoticeAck($user);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), ['acknowledged' => true, 'providers' => ['copilot']])->assertStatus(409);
        expect(txtNoticeTextAt($user))->toBeNull();
    });

    it('records both the provider set and the text acknowledgment for a new member', function () {
        config(['ai.ocr.enabled' => true]);
        [$user, $person, $doc] = txtNoticeSetup();

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202);

        $row = DB::table('ai_notice_acks')->where('user_id', $user->id)->first();
        expect($row->providers)->toBe('codex')->and($row->text_notice_acked_at)->not->toBeNull();
    });

    it('is not satisfied by the recipe acknowledgment', function () {
        config(['ai.ocr.enabled' => true]);
        [$user, $person, $doc] = txtNoticeSetup();
        DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'acknowledged_at' => now(), 'providers' => null, 'recipe_providers' => 'codex']);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), [])->assertStatus(409)->assertJson(['reason' => 'notice_required']);
    });

    it('asks again for a second provider but keeps the text acknowledgment', function () {
        config(['ai.ocr.enabled' => true]);
        [$user, $person, $doc] = txtNoticeSetup();
        $stamp = now()->subDay()->startOfSecond()->toDateTimeString();
        txtNoticeAck($user, $stamp);
        DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['enabled' => true]);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), [])
            ->assertStatus(409)->assertJson(['reason' => 'notice_required', 'providers' => ['codex', 'copilot']]);

        $this->actingAs($user)->postJson(txtNoticeUrl($person, $doc), ['acknowledged' => true, 'providers' => ['codex', 'copilot']])->assertStatus(202);
        expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('providers'))->toBe('codex,copilot');
        // The earlier text acknowledgment is kept, not re-asked and not required again.
        expect(txtNoticeTextAt($user))->not->toBeNull();
    });

    it('exposes textNotice and the acknowledgment state in the person page props', function () {
        [$user, $person] = txtNoticeSetup();
        txtNoticeAck($user);

        config(['ai.ocr.enabled' => false]);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ai.textNotice', false)->where('ai.noticeAcknowledged', true));

        config(['ai.ocr.enabled' => true]);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ai.textNotice', true)->where('ai.noticeAcknowledged', false));

        txtNoticeAck($user, now()->toDateTimeString());
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ai.noticeAcknowledged', true));
    });
});

describe('bulk start', function () {
    function txtNoticeDry(User $admin): string
    {
        $subject = MedicalFixtures::subject(['ai_consent' => true]);
        $doc = MedicalDocument::factory()->image()->forSubject($subject)->create();
        Storage::disk('medical')->put($doc->path, 'jpeg-bytes');

        return app(AdminAiBulkQuery::class)->dryRun($admin, 'no_proposal', 5)['token'];
    }

    it('answers notice_required without the text acknowledgment and writes nothing', function () {
        config(['ai.ocr.enabled' => true]);
        $admin = User::factory()->admin()->create();
        $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
        $token = txtNoticeDry($admin);

        $result = app(AdminAiBulkQuery::class)->start($admin, $token, true, $providers);

        expect($result['result'])->toBe('notice_required')
            ->and(DB::table('ai_bulk_runs')->count())->toBe(0)
            ->and(DB::table('ai_bulk_dry_runs')->where('id', $token)->value('consumed_at'))->toBeNull()
            ->and(DB::table('ai_notice_acks')->where('user_id', $admin->id)->exists())->toBeFalse();
    });

    it('starts with textNotice true and stores the acknowledgment; the panel follows it', function () {
        config(['ai.ocr.enabled' => true]);
        $admin = User::factory()->admin()->create();
        $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
        $token = txtNoticeDry($admin);
        expect(app(AdminAiBulkQuery::class)->panel($admin)['textNoticeAcknowledged'])->toBeFalse();

        $result = app(AdminAiBulkQuery::class)->start($admin, $token, true, $providers, true);

        expect($result['result'])->toBe('ok')->and($result['panel']['textNoticeAcknowledged'])->toBeTrue()
            ->and(DB::table('ai_bulk_runs')->where('status', 'active')->count())->toBe(1)
            ->and(txtNoticeTextAt($admin))->not->toBeNull();
    });

    it('needs no acknowledgment once it was given, including by a single run', function () {
        config(['ai.ocr.enabled' => true]);
        $admin = User::factory()->admin()->create();
        $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
        txtNoticeAck($admin, now()->toDateTimeString(), implode(',', $providers));
        $token = txtNoticeDry($admin);

        expect(app(AdminAiBulkQuery::class)->start($admin, $token, true, $providers)['result'])->toBe('ok');
    });

    it('is unchanged while full-text reading is off', function () {
        config(['ai.ocr.enabled' => false]);
        $admin = User::factory()->admin()->create();
        $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
        $token = txtNoticeDry($admin);

        expect(app(AdminAiBulkQuery::class)->start($admin, $token, true, $providers)['result'])->toBe('ok')
            ->and(txtNoticeTextAt($admin))->toBeNull();
    });

    it('answers 409 notice_required over HTTP and accepts textNotice', function () {
        config(['ai.ocr.enabled' => true]);
        $admin = User::factory()->admin()->create();
        $providers = app(AiAgentSettingsQuery::class)->enabledKeys();
        $token = txtNoticeDry($admin);
        $body = ['token' => $token, 'acknowledged' => true, 'providers' => $providers];

        $this->actingAs($admin)->postJson('/settings/ai-agents/bulk', $body)->assertStatus(409)->assertJson(['reason' => 'notice_required']);
        expect(DB::table('ai_bulk_runs')->count())->toBe(0);
        $this->actingAs($admin)->postJson('/settings/ai-agents/bulk', $body + ['textNotice' => 'maybe'])->assertStatus(422);
        $this->actingAs($admin)->postJson('/settings/ai-agents/bulk', $body + ['textNotice' => true])->assertStatus(201)
            ->assertJsonPath('panel.textNoticeAcknowledged', true);
    });
});
