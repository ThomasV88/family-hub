<?php

namespace App\Medical\Ai\Agents;

/** Phase 9 adds Copilot, order and fallback here. */
final class AgentRegistry
{
    /** @return list<Agent> */
    public function agents(): array
    {
        return [new CodexAgent];
    }

    public function primary(): Agent
    {
        return $this->agents()[0];
    }

    public function find(string $key): ?Agent
    {
        foreach ($this->agents() as $agent) {
            if ($agent->key() === $key) {
                return $agent;
            }
        }

        return null;
    }
}
