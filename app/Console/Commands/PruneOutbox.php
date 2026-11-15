<?php

namespace App\Console\Commands;

use App\Models\OutboxItem;
use Illuminate\Console\Command;

class PruneOutbox extends Command
{
    protected $signature = 'outbox:prune';

    protected $description = 'Delete finished outbox rows older than 30 days';

    public function handle(): int
    {
        $count = OutboxItem::query()
            ->whereIn('status', ['sent', 'skipped', 'failed'])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$count} outbox rows.");

        return 0;
    }
}
