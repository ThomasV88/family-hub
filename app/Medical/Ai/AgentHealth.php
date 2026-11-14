<?php

namespace App\Medical\Ai;

use App\Models\AiAgentStatus;
use Illuminate\Support\Carbon;

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
        $this->write($agent, ['last_success_at' => now()]);
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

    /** @return array{state: string, checkedAt: ?Carbon} */
    public function snapshot(string $agent = 'codex'): array
    {
        return [
            'state' => $this->display($agent),
            'checkedAt' => AiAgentStatus::query()->find($agent)?->checked_at,
        ];
    }

    /** @param array<string, mixed> $columns */
    private function write(string $agent, array $columns): void
    {
        $columns['updated_at'] = now();
        AiAgentStatus::query()->toBase()->upsert([['agent' => $agent, ...$columns]], ['agent'], array_keys($columns));
    }
}
