<?php

namespace App\Jobs;

use App\Models\SystemHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class RecordHeartbeat implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $source)
    {
        if (! in_array($source, ['schedule', 'manual'], true)) {
            throw new InvalidArgumentException("Invalid heartbeat source [{$source}].");
        }
    }

    public function handle(): void
    {
        SystemHeartbeat::create(['source' => $this->source, 'recorded_at' => now()]);

        SystemHeartbeat::where('recorded_at', '<', now()->subDays(7))->delete();
    }
}
