<?php

namespace App\Console\Commands;

use App\Medical\Ai\RunExtractionJob;
use App\Medical\Queries\AiBulkJobQuery;
use App\Medical\Queries\AiJobQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

class AiBulkTick extends Command
{
    protected $signature = 'ai:bulk-tick';

    protected $description = 'Advance the active bulk AI re-read by at most one document';

    public function handle(AiBulkJobQuery $bulk, AiJobQuery $jobs, Dispatcher $bus): int
    {
        $result = $bulk->tick(CarbonImmutable::now());

        // The row is committed as queued; a job that cannot be dispatched is failed, and the next tick reconciles the item.
        if ($result['enqueued'] !== null) {
            try {
                $job = new RunExtractionJob($result['enqueued']);
                $job->onQueue((string) config('ai.bulk.queue'));
                $bus->dispatch($job);
            } catch (Throwable) {
                $jobs->failQueued($result['enqueued']);
            }
        }

        // Counts only, never an id or a name.
        $this->line(sprintf('enqueued=%d reconciled=%d completed=%d', $result['enqueued'] === null ? 0 : 1, $result['reconciled'], $result['completed']));

        return 0;
    }
}
