<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\LoginState;
use Closure;

interface Agent
{
    public function key(): string;

    /** The configured model, or 'default' when none is set. */
    public function modelLabel(): string;

    public function extract(AgentInput $in, ?Closure $shouldAbort = null): AgentOutcome;

    public function loginStatus(): LoginState;
}
