<?php

use App\Medical\Ai\AgentHealth;
use App\Models\AiCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('walks the cooldown ladder and restarts after the reset window', function () {
    $h = new AgentHealth;
    $t = CarbonImmutable::parse('2026-11-14 10:00:00');
    $minutes = [];
    foreach (range(0, 4) as $i) {
        $now = $t->addHours($i);
        $h->recordQuotaHit('codex', null, null, $now);
        Carbon::setTestNow($now);
        $minutes[] = (int) $now->diffInMinutes($h->cooldownUntil('codex'));
    }
    expect($minutes)->toBe([60, 180, 720, 1440, 1440]);

    $later = $t->addHours(4 + 49);
    $h->recordQuotaHit('codex', null, null, $later);
    Carbon::setTestNow($later);
    expect((int) $later->diffInMinutes($h->cooldownUntil('codex')))->toBe(60);
});

it('uses the parsed reset time as is, and the signature default when larger', function () {
    $h = new AgentHealth;
    $now = CarbonImmutable::parse('2026-11-14 10:00:00');
    Carbon::setTestNow($now);
    $reset = $now->addMinutes(150);
    $h->recordQuotaHit('codex', $reset, 60, $now);
    expect($h->cooldownUntil('codex')->equalTo($reset))->toBeTrue();

    $h->recordSuccess('codex');
    $h->recordQuotaHit('copilot', null, 1440, $now);
    expect((int) $now->diffInMinutes($h->cooldownUntil('copilot')))->toBe(1440);
});

it('clears the cooldown on success and ignores a past cooldown', function () {
    $h = new AgentHealth;
    $now = CarbonImmutable::parse('2026-11-14 10:00:00');
    Carbon::setTestNow($now);
    $h->recordQuotaHit('codex', null, null, $now);
    expect($h->coolingDown('codex'))->toBeTrue();

    $h->recordSuccess('codex');
    expect($h->cooldownUntil('codex'))->toBeNull()->and($h->coolingDown('codex'))->toBeFalse()
        ->and($h->snapshot('codex')['cooldownUntil'])->toBeNull();
    $this->assertDatabaseHas('ai_agent_status', ['agent' => 'codex', 'quota_hits' => 0]);

    $h->recordQuotaHit('codex', null, null, $now->subHours(5));
    expect($h->cooldownUntil('codex'))->toBeNull();
});

it('stops Copilot at the daily ceiling counted from local midnight', function () {
    $h = new AgentHealth;
    Carbon::setTestNow('2026-11-14 12:00:00');
    config(['ai.local_timezone' => 'Europe/Bratislava', 'ai.copilot.daily_runs' => 30]);
    AiCall::factory()->count(29)->state(['agent' => 'copilot'])->succeeded()->create();
    AiCall::factory()->count(3)->state(['agent' => 'copilot', 'status' => 'cancelled'])->create();
    AiCall::factory()->state(['agent' => 'copilot', 'status' => 'failed', 'queued_at' => now()->subDay()])->create();
    expect($h->dailyCeilingReached('copilot'))->toBeFalse()->and($h->ceilingResetAt('copilot'))->toBeNull();

    AiCall::factory()->state(['agent' => 'copilot'])->failed()->create();
    expect($h->dailyCeilingReached('copilot'))->toBeTrue();
    $reset = $h->ceilingResetAt('copilot');
    expect($reset->setTimezone('Europe/Bratislava')->format('Y-m-d H:i:s'))->toBe('2026-11-15 00:00:00');

    config(['ai.copilot.daily_runs' => 0]);
    expect($h->dailyCeilingReached('copilot'))->toBeFalse();
});

it('never gives codex a ceiling', function () {
    config(['ai.copilot.daily_runs' => 1]);
    AiCall::factory()->count(3)->succeeded()->create();

    expect((new AgentHealth)->dailyCeilingReached('codex'))->toBeFalse();
});
