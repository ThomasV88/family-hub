<?php

namespace App\Console\Commands;

use App\Medical\Batches\BatchKicker;
use App\Medical\Queries\UploadBatchJobQuery;
use Illuminate\Console\Command;

class AiUploadBatchTick extends Command
{
    protected $signature = 'ai:upload-batch-tick';

    protected $description = 'Advance every open upload batch by at most one document';

    public function handle(UploadBatchJobQuery $batches, BatchKicker $kicker): int
    {
        $enqueued = 0;
        foreach ($batches->openBatchIds() as $id) {
            if ($kicker->kick($id)) {
                $enqueued++;
            }
        }

        // The count only, never an id or a name.
        $this->line(sprintf('enqueued=%d', $enqueued));

        return 0;
    }
}
