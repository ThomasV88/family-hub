<?php

use App\Access\Preset;
use App\Audit\AuditLogger;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Models\AiAgentSetting;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FailingAuditLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function aaAdmin(): User
{
    return User::factory()->admin()->create();
}

function aaEnabled(string $agent): bool
{
    return (bool) AiAgentSetting::query()->toBase()->where('agent', $agent)->value('enabled');
}

function aaOrder(array $agents): array
{
    return array_column($agents, 'key');
}

it('shows both agents in saved order with health and a future cooldown only', function () {
    $health = app(AgentHealth::class);
    $health->recordCheck('codex', LoginState::LoggedIn);
    $health->recordQuotaHit('copilot', now()->addHour()->toImmutable(), 60);
    $health->recordQuotaHit('codex', now()->subHour()->toImmutable(), 60);

    $this->actingAs(aaAdmin())->get('/settings/ai-agents')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/AiAgents', false)
        ->has('sections', 8)
        ->has('agents', 2)
        ->where('agents.0.key', 'codex')
        ->where('agents.0.enabled', true)
        ->where('agents.0.position', 1)
        ->where('agents.0.model', null)
        ->where('agents.0.health.state', 'logged_in')
        ->where('agents.0.cooldownUntil', null)
        ->where('agents.1.key', 'copilot')
        ->where('agents.1.enabled', false)
        ->where('agents.1.health.state', 'unknown')
        ->where('agents.1.health.checkedAt', null)
        ->whereNot('agents.1.cooldownUntil', null)
        ->where('providers', ['codex'])
        ->where('checkRoute', 'settings.ai_calls.check'));
});

it('refuses every non-admin identically and bounces guests', function () {
    foreach ([Preset::Parent, Preset::Child, Preset::Grandparent] as $preset) {
        $u = User::factory()->withPreset($preset)->create();
        $this->actingAs($u)->get('/settings/ai-agents')->assertForbidden();
        $this->actingAs($u)->patchJson('/settings/ai-agents/copilot', ['enabled' => true])->assertForbidden();
        $this->actingAs($u)->postJson('/settings/ai-agents/copilot/move', ['direction' => 'up'])->assertForbidden();
    }
    auth()->logout();
    $this->get('/settings/ai-agents')->assertRedirect();
});

it('switches copilot on with one audit row', function () {
    $admin = aaAdmin();
    $this->actingAs($admin)->patchJson('/settings/ai-agents/copilot', ['enabled' => true])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('agents.1.enabled', true);

    expect(aaEnabled('copilot'))->toBeTrue()
        ->and(AuditEntry::where('event', 'ai_agent_setting_changed')->count())->toBe(1);
});

it('needs a recent confirmation', function () {
    $this->useRealSensitiveSession();
    $this->actingAs(aaAdmin())->patchJson('/settings/ai-agents/copilot', ['enabled' => true])->assertStatus(423);
    expect(aaEnabled('copilot'))->toBeFalse();
});

it('changes nothing when the audit write fails', function () {
    $this->app->instance(AuditLogger::class, new FailingAuditLogger);
    $this->actingAs(aaAdmin())->patchJson('/settings/ai-agents/copilot', ['enabled' => true])->assertStatus(500);
    expect(aaEnabled('copilot'))->toBeFalse();
});

it('refuses to switch off the last enabled agent', function () {
    $this->actingAs(aaAdmin())->patchJson('/settings/ai-agents/codex', ['enabled' => false])
        ->assertStatus(422)->assertJsonPath('reason', 'last_enabled');
    expect(aaEnabled('codex'))->toBeTrue();
});

it('saves, clears and refuses models', function () {
    $admin = aaAdmin();
    $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => 'gpt-5.6-sol'])
        ->assertOk()->assertJsonPath('agents.0.model', 'gpt-5.6-sol');
    $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => ''])
        ->assertOk()->assertJsonPath('agents.0.model', null);
    foreach (['a/b', '-x', str_repeat('a', 61), 'has space'] as $bad) {
        $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => $bad])
            ->assertStatus(422)->assertJsonPath('reason', 'invalid_model');
    }
});

it('404s an unknown agent', function () {
    $admin = aaAdmin();
    $this->actingAs($admin)->patchJson('/settings/ai-agents/claude', ['enabled' => true])->assertNotFound();
    $this->actingAs($admin)->postJson('/settings/ai-agents/claude/move', ['direction' => 'up'])->assertNotFound();
});

it('moves agents, treats an end as a no-op and reports a stale order', function () {
    $admin = aaAdmin();
    $r = $this->actingAs($admin)->postJson('/settings/ai-agents/copilot/move', ['direction' => 'up'])->assertOk();
    expect(aaOrder($r->json('agents')))->toBe(['copilot', 'codex']);

    $r = $this->actingAs($admin)->postJson('/settings/ai-agents/copilot/move', ['direction' => 'up'])->assertOk();
    expect(aaOrder($r->json('agents')))->toBe(['copilot', 'codex']);

    $r = $this->actingAs($admin)->postJson('/settings/ai-agents/codex/move', ['direction' => 'up', 'expectedPosition' => 1])
        ->assertStatus(409)->assertJsonPath('reason', 'stale');
    expect(aaOrder($r->json('agents')))->toBe(['copilot', 'codex']);

    $this->actingAs($admin)->postJson('/settings/ai-agents/codex/move', ['direction' => 'sideways'])->assertStatus(422);
});

it('throttles the 31st update in a minute', function () {
    $admin = aaAdmin();
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => 'm'.$i])->assertOk();
    }
    $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => 'x'])->assertStatus(429);
});

it('exposes no credential, token or path', function () {
    $admin = aaAdmin();
    $this->actingAs($admin)->patchJson('/settings/ai-agents/codex', ['model' => 'gpt-5.6-sol']);
    $json = $this->actingAs($admin)->postJson('/settings/ai-agents/copilot/move', ['direction' => 'up'])->getContent();
    expect(preg_match('~token|auth|password|config\.json|/copilot|/codex~i', $json))->toBe(0);
    $props = $this->actingAs($admin)->get('/settings/ai-agents')->viewData('page')['props'];
    unset($props['sections'], $props['auth'], $props['errors'], $props['flash'], $props['locale'], $props['csrf_token']);
    $flat = json_encode(['agents' => $props['agents'], 'providers' => $props['providers']]);
    expect(preg_match('~token|auth|password|config\.json|/copilot|/codex~i', $flat))->toBe(0);
});
