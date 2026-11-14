<?php

namespace App\Medical\Ai;

use App\Medical\Ai\Agents\AgentRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Asks every agent for its login state and records the enum; never stores text. */
class CheckLoginJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue((string) config('ai.queue'));
    }

    public function handle(AgentRegistry $registry, AgentHealth $health): void
    {
        foreach ($registry->agents() as $agent) {
            $health->recordCheck($agent->key(), $agent->loginStatus());
        }
    }
}
