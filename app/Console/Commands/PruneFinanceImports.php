<?php

namespace App\Console\Commands;

use App\Finance\Queries\ImportQuery;
use Illuminate\Console\Command;

class PruneFinanceImports extends Command
{
    protected $signature = 'finance:prune-imports';

    protected $description = 'Removes abandoned draft CSV import batches (and their staged rows) older than the draft TTL';

    public function handle(ImportQuery $imports): int
    {
        $hours = (int) config('finance.import.draft_ttl_hours', 24);
        $removed = $imports->pruneDrafts(now()->subHours($hours));

        $this->info("Pruned {$removed} abandoned import drafts.");

        return 0;
    }
}
