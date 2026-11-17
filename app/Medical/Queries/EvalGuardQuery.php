<?php

namespace App\Medical\Queries;

use Illuminate\Support\Facades\DB;

/** The "is the AI worker free?" check of the evaluation. Reads queue and status columns only, writes nothing. */
final class EvalGuardQuery
{
    /** @return string|null a fixed reason code when family AI work could collide with the evaluation, else null */
    public static function busyReason(): ?string
    {
        $queues = array_values(array_unique([(string) config('ai.queue'), (string) config('ai.bulk.queue')]));
        if (DB::table('jobs')->whereIn('queue', $queues)->exists()) {
            return 'ai_queue_busy';
        }
        if (DB::table('ai_bulk_runs')->whereIn('status', ['active', 'paused'])->exists()) {
            return 'bulk_run_open';
        }
        if (DB::table('medical_ai_extractions')->whereIn('status', ['queued', 'running', 'waiting'])->exists()) {
            return 'extraction_open';
        }

        return null;
    }
}
