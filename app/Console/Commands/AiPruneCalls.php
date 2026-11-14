<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiPruneCalls extends Command
{
    protected $signature = 'ai:prune-calls';

    protected $description = 'Delete AI call rows older than the retention period (default 12 months, never fewer than 12)';

    public function handle(): int
    {
        // The runtime role cannot DELETE from ai_calls; the definer function is the only removal path.
        $row = DB::selectOne('select ai_calls_prune(?) as n', [max(12, (int) config('ai.call_retention_months', 12))]);
        $this->info('Pruned '.(int) $row->n.' AI call rows.');

        return 0;
    }
}
