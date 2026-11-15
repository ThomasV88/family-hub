<?php

namespace App\Console\Commands;

use App\Medical\Queries\AiJobQuery;
use Illuminate\Console\Command;

class AiReapStuck extends Command
{
    protected $signature = 'ai:reap-stuck';

    protected $description = 'Close AI runs a dead worker left behind (running past the job timeout, queued for hours)';

    public function handle(AiJobQuery $jobs): int
    {
        $count = $jobs->reapStuck(
            (int) config('ai.timeouts.job') + 120,
            (int) config('ai.timeouts.queued_stale_hours'),
        );
        $this->line((string) $count);

        return 0;
    }
}
