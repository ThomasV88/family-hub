<?php

use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\Agents\CopilotAgent;
use App\Medical\Ai\LoginState;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Models\AiAgentSetting;
use App\Models\AiCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reg = fn () => app(AgentRegistry::class);
    $this->health = new AgentHealth;
    $this->enableCopilot = fn () => AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    $this->keys = fn (array $agents) => array_map(fn ($a) => $a->key(), $agents);
});

afterEach(fn () => Carbon::setTestNow());

it('follows the saved order with no caching', function () {
    ($this->enableCopilot)();
    expect(($this->keys)(($this->reg)()->enabled()))->toBe(['codex', 'copilot']);

    app(AiAgentSettingsQuery::class)->move($this->admin, 'copilot', 'up');
    expect(($this->keys)(($this->reg)()->enabled()))->toBe(['copilot', 'codex'])
        ->and(($this->reg)()->primary()->key())->toBe('copilot')
        ->and(($this->reg)()->providers())->toBe(['copilot', 'codex']);
});

it('excludes disabled, expired, cooling and ceiling agents but keeps unknown logins', function () {
    expect(($this->keys)(($this->reg)()->candidates()))->toBe(['codex']);

    ($this->enableCopilot)();
    expect(($this->keys)(($this->reg)()->candidates()))->toBe(['codex', 'copilot'])
        ->and(($this->keys)(($this->reg)()->candidates(['codex'])))->toBe(['copilot'])
        ->and(($this->reg)()->nextCandidate(['codex'])->key())->toBe('copilot');

    $this->health->recordCheck('copilot', LoginState::LoggedOut);
    expect(($this->keys)(($this->reg)()->candidates()))->toBe(['codex']);

    $this->health->recordCheck('copilot', LoginState::LoggedIn);
    $this->health->recordQuotaHit('codex', null, null);
    expect(($this->keys)(($this->reg)()->candidates()))->toBe(['copilot']);

    config(['ai.copilot.daily_runs' => 1]);
    AiCall::factory()->state(['agent' => 'copilot'])->succeeded()->create();
    expect(($this->reg)()->candidates())->toBe([])
        ->and(($this->reg)()->nextCandidate([]))->toBeNull();
});

it('limits bulk to the first enabled agent unless fallback is configured', function () {
    ($this->enableCopilot)();
    $this->health->recordQuotaHit('codex', null, null);

    expect(($this->reg)()->candidates([], true))->toBe([])
        ->and(($this->keys)(($this->reg)()->candidates([], false)))->toBe(['copilot']);

    config(['ai.bulk.fallback_agents' => true]);
    expect(($this->keys)(($this->reg)()->candidates([], true)))->toBe(['copilot']);
});

it('reports the earliest resume among runnable agents only', function () {
    $now = CarbonImmutable::now();
    ($this->enableCopilot)();
    expect(($this->reg)()->earliestResume())->toBeNull();

    $this->health->recordQuotaHit('codex', $now->addHours(3), null);
    $this->health->recordQuotaHit('copilot', $now->addHours(1), null);
    expect(($this->reg)()->earliestResume()->diffInSeconds($now->addHours(1), true))->toBeLessThan(2);

    $this->health->recordCheck('copilot', LoginState::LoggedOut);
    expect(($this->reg)()->earliestResume()->diffInSeconds($now->addHours(3), true))->toBeLessThan(2);
});

it('says anyRunnable is true while cooling and false when every enabled agent is expired', function () {
    $this->health->recordQuotaHit('codex', null, null);
    expect(($this->reg)()->anyRunnable())->toBeTrue();

    $this->health->recordCheck('codex', LoginState::LoggedOut);
    expect(($this->reg)()->anyRunnable())->toBeFalse();
});

it('builds copilot with the saved model', function () {
    app(AiAgentSettingsQuery::class)->setModel($this->admin, 'copilot', 'gpt-5.6-sol');
    $agent = ($this->reg)()->find('copilot');

    expect($agent)->toBeInstanceOf(CopilotAgent::class)->and($agent->modelLabel())->toBe('gpt-5.6-sol')
        ->and(($this->reg)()->find('nope'))->toBeNull();
});

it('never offers an agent outside the allowed keys', function () {
    ($this->enableCopilot)();
    $now = CarbonImmutable::now();
    $this->health->recordQuotaHit('codex', $now->addHours(2), null);

    expect(($this->reg)()->candidates([], false, ['codex']))->toBe([])
        ->and(($this->reg)()->nextCandidate([], false, ['codex']))->toBeNull()
        ->and(($this->reg)()->earliestResume(false, ['codex'])->diffInSeconds($now->addHours(2), true))->toBeLessThan(2)
        ->and(($this->keys)(($this->reg)()->candidates([], false, ['copilot'])))->toBe(['copilot']);
});
