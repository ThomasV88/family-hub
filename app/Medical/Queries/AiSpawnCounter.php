<?php

namespace App\Medical\Queries;

use App\Medical\Ai\LocalDay;
use Illuminate\Support\Facades\DB;

/** The per-local-day count of CLI spawns per agent (ai_agent_status.spawn_*). Integers and a date only. */
final class AiSpawnCounter
{
    /** Counts one spawn on the local day, atomically; a new local day starts again at 1. */
    public function record(string $agent): void
    {
        DB::statement(
            'insert into ai_agent_status (agent, spawn_day, spawn_count, updated_at) values (?, ?, 1, ?) '
            .'on conflict (agent) do update set '
            .'spawn_count = case when ai_agent_status.spawn_day = excluded.spawn_day then ai_agent_status.spawn_count + 1 else 1 end, '
            .'spawn_day = excluded.spawn_day, updated_at = excluded.updated_at',
            [$agent, LocalDay::date(), now()],
        );
    }
}
