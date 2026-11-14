<?php

namespace App\Console\Commands;

use App\Medical\Ai\RunExtractionJob;
use App\Medical\Queries\AiJobQuery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

class AiResumeWaiting extends Command
{
    protected $signature = 'ai:resume-waiting';

    protected $description = 'Put AI runs whose agent usage limit has reset back in the queue';

    public function handle(AiJobQuery $jobs, Dispatcher $bus): int
    {
        $rows = $jobs->resumeDue();

        // The rows are already committed as queued; a job that cannot be dispatched goes back to waiting for a minute.
        foreach ($rows as $row) {
            try {
                $job = new RunExtractionJob($row['id']);
                $job->onQueue((string) ($row['bulk_run_id'] !== null ? config('ai.bulk.queue') : config('ai.queue')));
                $bus->dispatch($job);
            } catch (Throwable) {
                $jobs->rewait($row['id']);
            }
        }

        // The count only, never an id or a name.
        $this->line((string) count($rows));

        return 0;
    }
}
