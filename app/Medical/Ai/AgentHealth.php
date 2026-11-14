<?php

namespace App\Medical\Ai;

use App\Models\AiAgentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Login health of the AI agents: written by the worker (check job, real run outcomes), read by the app.
 * Holds enums and timestamps only, never a credential or CLI text.
 */
final class AgentHealth
{
    public function recordCheck(string $agent, LoginState $state): void
    {
        $this->write($agent, ['login_state' => $state->value, 'checked_at' => now()]);
    }

    public function recordAuthFailure(string $agent): void
    {
        $this->write($agent, ['last_auth_failure_at' => now()]);
    }

    public function recordSuccess(string $agent): void
    {
        $this->write($agent, ['last_success_at' => now(), 'quota_hits' => 0, 'cooldown_until' => null]);
    }

    /**
     * A usage-limit hit: rest until the parsed reset time, else a ladder by repeated hits.
     * $resetAt is already buffered and clamped by QuotaSignatures.
     */
    public function recordQuotaHit(string $agent, ?CarbonImmutable $resetAt, ?int $defaultMinutes, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $row = AiAgentStatus::query()->toBase()->where('agent', $agent)->first(['quota_hits', 'last_quota_at']);
        $previous = $row?->last_quota_at === null ? null : CarbonImmutable::parse($row->last_quota_at);
        $hits = ($previous !== null && $previous->gte($now->subHours((int) config('ai.quota.ladder_reset_hours', 48))))
            ? (int) $row->quota_hits + 1
            : 1;

        if ($resetAt !== null) {
            $until = $resetAt;
        } else {
            $ladder = array_values((array) config('ai.quota.ladder', [60, 180, 720, 1440]));
            $rung = (int) ($ladder[min($hits, count($ladder)) - 1] ?? 60);
            $until = $now->addMinutes(max((int) $defaultMinutes, $rung));
        }

        $this->write($agent, ['quota_hits' => $hits, 'cooldown_until' => $until, 'last_quota_at' => $now]);
    }

    public function cooldownUntil(string $agent): ?CarbonImmutable
    {
        $value = AiAgentStatus::query()->toBase()->where('agent', $agent)->value('cooldown_until');
        if ($value === null) {
            return null;
        }
        $until = CarbonImmutable::parse($value);

        return $until->isFuture() ? $until : null;
    }

    public function coolingDown(string $agent): bool
    {
        return $this->cooldownUntil($agent) !== null;
    }

    /**
     * Counts one CLI spawn of the agent on the local day, atomically. The repair call is a second spawn of the same attempt.
     * Called by the agent right before it starts the process.
     */
    public function recordSpawn(string $agent): void
    {
        $today = LocalDay::date();
        $now = now();
        DB::statement(
            'insert into ai_agent_status (agent, spawn_day, spawn_count, updated_at) values (?, ?, 1, ?) '
            .'on conflict (agent) do update set '
            .'spawn_count = case when ai_agent_status.spawn_day = excluded.spawn_day then ai_agent_status.spawn_count + 1 else 1 end, '
            .'spawn_day = excluded.spawn_day, updated_at = excluded.updated_at',
            [$agent, $today, $now],
        );
    }

    /** CLI spawns of the agent on the current local day. */
    public function spawnsToday(string $agent): int
    {
        $row = AiAgentStatus::query()->toBase()->where('agent', $agent)->first(['spawn_day', 'spawn_count']);

        return ($row !== null && $row->spawn_day === LocalDay::date()) ? (int) $row->spawn_count : 0;
    }

    /**
     * Copilot only: spawns today (local day) have used up the AUP guard. Bulk may use only part of it
     * (ai.copilot.bulk_reserve spawns stay free for interactive runs).
     */
    public function dailyCeilingReached(string $agent, bool $bulk = false): bool
    {
        $limit = (int) config('ai.copilot.daily_runs', 0);
        if ($agent !== 'copilot' || $limit <= 0) {
            return false;
        }
        if ($bulk) {
            $limit = max(0, $limit - max(0, (int) config('ai.copilot.bulk_reserve', 0)));
        }

        return $this->spawnsToday($agent) >= $limit;
    }

    public function ceilingResetAt(string $agent, bool $bulk = false): ?CarbonImmutable
    {
        if (! $this->dailyCeilingReached($agent, $bulk)) {
            return null;
        }

        return LocalDay::nextStartUtc();
    }

    /** @return 'logged_in'|'expired'|'not_responding'|'unknown' */
    public function display(string $agent = 'codex'): string
    {
        $row = AiAgentStatus::query()->find($agent);
        if ($row === null || $row->checked_at === null) {
            return 'unknown';
        }
        if ($row->checked_at->lt(now()->subMinutes((int) config('ai.login_stale_minutes', 15)))) {
            return 'not_responding';
        }
        if (in_array($row->login_state, [LoginState::LoggedOut->value, LoginState::WrongMode->value], true)) {
            return 'expired';
        }
        // The check ran but could not tell (timeout, odd output): the service is not answering.
        if ($row->login_state === LoginState::Unknown->value) {
            return 'not_responding';
        }
        $failure = $row->last_auth_failure_at;
        if ($failure !== null
            && ($row->last_success_at === null || $failure->gt($row->last_success_at))
            && $failure->gt($row->checked_at)) {
            return 'expired';
        }

        return 'logged_in';
    }

    /** 'unknown' is runnable: the UI says "not known to be expired" (PROVISIONAL). */
    public function runnable(string $agent = 'codex'): bool
    {
        return ! in_array($this->display($agent), ['expired', 'not_responding'], true);
    }

    /** @return array{state: string, checkedAt: ?Carbon, cooldownUntil: ?CarbonImmutable} */
    public function snapshot(string $agent = 'codex'): array
    {
        return [
            'state' => $this->display($agent),
            'checkedAt' => AiAgentStatus::query()->find($agent)?->checked_at,
            'cooldownUntil' => $this->cooldownUntil($agent),
        ];
    }

    /** @param array<string, mixed> $columns */
    private function write(string $agent, array $columns): void
    {
        $columns['updated_at'] = now();
        AiAgentStatus::query()->toBase()->upsert([['agent' => $agent, ...$columns]], ['agent'], array_keys($columns));
    }
}
