<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\AgentHealth;
use App\Medical\Queries\AiAgentSettingsQuery;
use Carbon\CarbonImmutable;

/**
 * The known agents in the admin's saved order. Holds no state: the settings and health are read fresh on every call,
 * because the worker process is long-lived.
 */
final class AgentRegistry
{
    public function __construct(private readonly AiAgentSettingsQuery $settings, private readonly AgentHealth $health) {}

    /** @return list<Agent> one instance per known key, in config order */
    public function all(): array
    {
        $agents = [];
        foreach ((array) config('ai.agents', []) as $key) {
            $agent = $this->make((string) $key);
            if ($agent !== null) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }

    public function find(string $key): ?Agent
    {
        return in_array($key, array_map('strval', (array) config('ai.agents', [])), true) ? $this->make($key) : null;
    }

    /** @return list<Agent> enabled agents in saved order */
    public function enabled(): array
    {
        $enabled = [];
        foreach ($this->settings->enabledKeys() as $key) {
            $agent = $this->find($key);
            if ($agent !== null) {
                $enabled[] = $agent;
            }
        }

        return $enabled;
    }

    public function primary(): Agent
    {
        return $this->enabled()[0] ?? $this->all()[0];
    }

    /**
     * @param  list<string>  $excludeKeys
     * @param  list<string>|null  $allowedKeys
     * @return list<Agent>
     */
    public function candidates(array $excludeKeys = [], bool $bulk = false, ?array $allowedKeys = null): array
    {
        return array_values(array_filter(
            $this->considered($bulk, $allowedKeys),
            fn (Agent $a) => ! in_array($a->key(), $excludeKeys, true)
                && $this->health->runnable($a->key())
                && ! $this->health->coolingDown($a->key())
                && ! $this->health->dailyCeilingReached($a->key()),
        ));
    }

    /**
     * @param  list<string>  $tried
     * @param  list<string>|null  $allowedKeys
     */
    public function nextCandidate(array $tried, bool $bulk = false, ?array $allowedKeys = null): ?Agent
    {
        return $this->candidates($tried, $bulk, $allowedKeys)[0] ?? null;
    }

    /** @param  list<string>|null  $allowedKeys */
    public function earliestResume(bool $bulk = false, ?array $allowedKeys = null): ?CarbonImmutable
    {
        $earliest = null;
        foreach ($this->considered($bulk, $allowedKeys) as $agent) {
            $key = $agent->key();
            if (! $this->health->runnable($key)) {
                continue;
            }
            foreach ([$this->health->cooldownUntil($key), $this->health->ceilingResetAt($key)] as $at) {
                if ($at !== null && ($earliest === null || $at->lt($earliest))) {
                    $earliest = $at;
                }
            }
        }

        return $earliest;
    }

    /** Cooling down still counts as available. */
    public function anyRunnable(): bool
    {
        foreach ($this->enabled() as $agent) {
            if ($this->health->runnable($agent->key())) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return $this->settings->enabledKeys();
    }

    /**
     * Enabled agents the run may consider: restricted to the allowed keys, and to the first enabled agent for bulk.
     *
     * @param  list<string>|null  $allowedKeys
     * @return list<Agent>
     */
    private function considered(bool $bulk, ?array $allowedKeys): array
    {
        $agents = $this->enabled();
        if ($bulk && ! config('ai.bulk.fallback_agents')) {
            $agents = array_slice($agents, 0, 1);
        }
        if ($allowedKeys !== null) {
            $agents = array_values(array_filter($agents, fn (Agent $a) => in_array($a->key(), $allowedKeys, true)));
        }

        return $agents;
    }

    private function make(string $key): ?Agent
    {
        $model = $this->settings->model($key);

        return match ($key) {
            'codex' => new CodexAgent(null, $model),
            'copilot' => new CopilotAgent(null, $model),
            default => null,
        };
    }
}
