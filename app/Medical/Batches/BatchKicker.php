<?php

namespace App\Medical\Batches;

use App\Medical\Ai\RunExtractionJob;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\UploadBatchJobQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/**
 * Advances a batch by at most one document and dispatches the job after the transaction committed. Call it outside any
 * open transaction. A job that cannot be dispatched is failed through the existing path, so the batch is not stuck.
 */
final class BatchKicker
{
    public function __construct(
        private readonly UploadBatchJobQuery $batches,
        private readonly AiJobQuery $jobs,
        private readonly Dispatcher $bus,
    ) {}

    /** @return bool whether a run was started */
    public function kick(string $batchId): bool
    {
        $id = $this->batches->advance($batchId, CarbonImmutable::now());
        if ($id === null) {
            return false;
        }
        try {
            $job = new RunExtractionJob($id);
            $job->onQueue((string) config('ai.queue'));
            $this->bus->dispatch($job);
        } catch (Throwable) {
            $this->jobs->failQueued($id);
        }

        return true;
    }
}
