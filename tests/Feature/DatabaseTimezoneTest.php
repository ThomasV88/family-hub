<?php

use App\Models\AiAgentStatus;
use Illuminate\Support\Facades\DB;

it('talks to Postgres in UTC so timestamptz columns hold the instant the app means', function () {
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC');

    $this->travelTo(now()->startOfSecond());
    AiAgentStatus::query()->toBase()->upsert([['agent' => 'codex', 'login_state' => 'logged_in', 'checked_at' => now(), 'updated_at' => now()]], ['agent']);

    // What the app stored must come back as the same instant, and as "just now" for the staleness check.
    expect(AiAgentStatus::find('codex')->checked_at->getTimestamp())->toBe(now()->getTimestamp());
});
