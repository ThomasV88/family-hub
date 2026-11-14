<?php

namespace App\Medical\Queries;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unscoped by design: system side of bulk runs, no viewer. Lifecycle columns only; never returns names.
 * Nothing here writes to a record table or touches an accepted decision.
 */
final class AiBulkJobQuery
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Stops the run's work: queued and waiting extractions become cancelled(user) at once (call rows closed, one audit
     * row each), a running one is asked to stop at its next poll. Returns how many extractions that touched.
     */
    public function cancelRunExtractions(string $runId, ?int $actorId): int
    {
        if (! Str::isUuid($runId)) {
            return 0;
        }
        $actor = $actorId === null ? null : User::query()->find($actorId);

        return DB::transaction(function () use ($runId, $actor) {
            $now = Carbon::now();
            $count = 0;

            $open = DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->whereIn('status', ['queued', 'waiting'])
                ->lockForUpdate()->get(['id', 'subject_id', 'document_id', 'status']);
            foreach ($open as $row) {
                // available_at goes in the same UPDATE (CHECK: set exactly while waiting); waiting_since stays as history.
                $changed = DB::table('medical_ai_extractions')->where('id', $row->id)->where('status', $row->status)->update([
                    'status' => 'cancelled', 'cancel_reason' => 'user', 'model' => 'default', 'available_at' => null,
                    'finished_at' => $now, 'updated_at' => $now,
                ]);
                if ($changed === 0) {
                    continue;
                }
                $count++;
                DB::table('ai_calls')->where('extraction_id', $row->id)->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'cancelled', 'model' => 'default', 'finished_at' => $now,
                ]);
                DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)->where('extraction_id', $row->id)->whereNull('outcome')
                    ->update(['outcome' => 'cancelled']);
                $this->audit->record(AuditEvent::MedicalAiRunFinished, $actor, [
                    'subject' => (string) $row->subject_id, 'document' => (string) $row->document_id, 'status' => 'cancelled',
                ], null, Module::Health);
            }

            $count += DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->where('status', 'running')
                ->whereNull('cancel_requested_at')->update(['cancel_requested_at' => $now, 'updated_at' => $now]);

            return $count;
        });
    }
}
