<?php

namespace App\Console\Commands;

use App\Medical\Queries\UploadBatchJobQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireUploadBatches extends Command
{
    protected $signature = 'medical:expire-upload-batches';

    protected $description = 'Delete upload batches past their expiry with their unfinished documents';

    public function handle(UploadBatchJobQuery $batches): int
    {
        $result = $batches->expireDue(CarbonImmutable::now());

        // Counts only, never an id, a title or a path.
        $this->line(sprintf('expired=%d removed=%d', $result['expired'], $result['removed']));

        return 0;
    }
}
