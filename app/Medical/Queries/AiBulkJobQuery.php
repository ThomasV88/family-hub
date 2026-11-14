<?php

namespace App\Medical\Queries;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\AiConsent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unscoped by design: system side of bulk runs, no viewer. Lifecycle columns only; never returns names.
 * Nothing here writes to a record table or touches an accepted decision.
 */
final class AiBulkJobQuery
{
    /** Test seam: runs after the candidate is picked and before the person row is locked (a race window). */
    public static ?Closure $afterPick = null;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AiBulkEligibility $eligibility,
        private readonly AgentRegistry $agents,
    ) {}

    /**
     * One scheduler tick: reconcile finished items, complete the run when nothing is left, apply the pacing gates and
     * enqueue at most one document. Returns the extraction id to dispatch after commit. Counts and ids only.
     *
     * @return array{enqueued: ?string, reconciled: int, completed: int}
     */
    public function tick(CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($now) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('ai-bulk-tick'))");

            DB::table('ai_bulk_dry_runs')->where('created_at', '<', $now->subDay())->delete();
            $reconciled = $this->reconcile();

            $run = DB::table('ai_bulk_runs')->where('status', 'active')->lockForUpdate()->first();
            if ($run === null) {
                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 0];
            }
            $runId = (string) $run->id;
            // Run viability: a run whose starter lost the admin role is ended, and its queued work stopped. (A run with no usable
            // acknowledged provider is held further down, visibly, via blocked_since.)
            if (! $this->starterIsAdmin($run)) {
                $this->cancelUnviable($run, $now);

                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 0];
            }

            $open = DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)->whereNull('outcome')->exists();
            $candidate = $this->eligibility->nextCandidate((string) $run->scope, $runId);

            // Completion runs before the pacing gates: a cap or off-peak pause must not delay it. A paused run never gets here.
            if ($candidate === null && ! $open) {
                $this->complete($run, $now);

                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 1];
            }
            if ($candidate === null || $open) {
                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 0];
            }

            $local = $now->setTimezone((string) config('ai.local_timezone'));
            $enqueuedToday = DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)
                ->where('enqueued_at', '>=', $local->startOfDay())->count();
            $spacing = (int) config('ai.bulk.spacing_minutes');
            $last = $run->last_enqueued_at === null ? null : CarbonImmutable::parse($run->last_enqueued_at);

            $paced = $local->hour >= (int) config('ai.bulk.start_hour')
                && $enqueuedToday < (int) $run->daily_cap
                && ($last === null || $last->addMinutes($spacing)->lte($now))
                && DB::table('medical_ai_extractions')->whereIn('status', ['queued', 'running', 'waiting'])->count() < (int) config('ai.bulk.max_global_active');
            if (! $paced) {
                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 0];
            }

            // The same acknowledged set the job re-applies before each attempt, so the tick never picks an agent the job would refuse.
            $agent = $this->agents->candidates([], true, $this->ackedProviders((int) $run->started_by))[0] ?? null;
            if ($agent === null) {
                if ($run->blocked_since === null) {
                    DB::table('ai_bulk_runs')->where('id', $runId)->update(['blocked_since' => $now, 'updated_at' => $now]);
                }

                return ['enqueued' => null, 'reconciled' => $reconciled, 'completed' => 0];
            }

            $id = $this->enqueue($run, $candidate, $agent->key(), $now);

            return ['enqueued' => $id, 'reconciled' => $reconciled, 'completed' => 0];
        });
    }

    private function starterIsAdmin(object $run): bool
    {
        return (bool) User::query()->whereKey((int) $run->started_by)->value('is_admin');
    }

    /** @return list<string> */
    private function ackedProviders(int $userId): array
    {
        $providers = DB::table('ai_notice_acks')->where('user_id', $userId)->value('providers');

        return $providers === null ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $providers)), fn ($p) => $p !== ''));
    }

    /** The starting admin is gone or no longer an admin: end the run, stop its queued work and say so in the audit trail. */
    private function cancelUnviable(object $run, CarbonImmutable $now): void
    {
        $runId = (string) $run->id;
        DB::table('ai_bulk_runs')->where('id', $runId)->update(['status' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now]);
        $stopped = $this->cancelRunExtractions($runId, null);
        $this->audit->record(AuditEvent::AiBulkRunChanged, User::query()->find((int) $run->started_by), [
            'action' => 'cancelled', 'scope' => (string) $run->scope, 'count' => $stopped,
        ]);
    }

    /** Items whose extraction ended get their outcome. Idempotent; never touches an item that already has one. */
    private function reconcile(): int
    {
        $count = 0;
        $items = DB::table('ai_bulk_run_items as i')->leftJoin('medical_ai_extractions as e', 'e.id', '=', 'i.extraction_id')
            ->whereNull('i.outcome')
            ->get(['i.bulk_run_id', 'i.document_id', 'e.id as ext_id', 'e.status', 'e.cancel_reason']);
        foreach ($items as $item) {
            $outcome = match (true) {
                $item->ext_id === null => 'gone',
                in_array($item->status, ['ready', 'superseded'], true) => 'read',
                $item->status === 'failed' => 'failed',
                $item->status === 'cancelled' => $item->cancel_reason === 'decided' ? 'skipped' : 'cancelled',
                default => null,
            };
            if ($outcome === null) {
                continue;
            }
            $count += DB::table('ai_bulk_run_items')->where('bulk_run_id', $item->bulk_run_id)->where('document_id', $item->document_id)
                ->whereNull('outcome')->update(['outcome' => $outcome]);
        }

        return $count;
    }

    private function complete(object $run, CarbonImmutable $now): void
    {
        $runId = (string) $run->id;
        $by = DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where outcome = 'skipped') as skipped")
            ->selectRaw("count(*) filter (where outcome = 'read') as read")->first();
        $skipped = (int) $by->skipped + max(0, (int) $run->eligible_total - (int) $by->total);
        DB::table('ai_bulk_runs')->where('id', $runId)->update([
            'status' => 'completed', 'finished_at' => $now, 'skipped_count' => $skipped, 'updated_at' => $now,
        ]);
        $actor = User::query()->find((int) $run->started_by);
        $this->audit->record(AuditEvent::AiBulkRunChanged, $actor, [
            'action' => 'completed', 'scope' => (string) $run->scope, 'count' => (int) $by->read,
        ]);
    }

    /** @param array{document_id: string, subject_id: string} $candidate */
    private function enqueue(object $run, array $candidate, string $agentKey, CarbonImmutable $now): ?string
    {
        $runId = (string) $run->id;
        $docId = $candidate['document_id'];
        $subjectId = $candidate['subject_id'];
        $skip = function () use ($runId, $docId, $now): ?string {
            DB::table('ai_bulk_run_items')->insertOrIgnore([
                'bulk_run_id' => $runId, 'document_id' => $docId, 'extraction_id' => null, 'enqueued_at' => $now, 'outcome' => 'skipped',
            ]);

            return null;
        };

        if (self::$afterPick !== null) {
            (self::$afterPick)($candidate);
        }
        // A concurrent consent change takes FOR UPDATE on this row and so serializes with the share lock.
        $person = DB::table('medical_subjects')->where('id', $subjectId)->sharedLock()->first(['id']);
        $document = DB::table('medical_documents')->where('id', $docId)->where('subject_id', $subjectId)->first(['id', 'kind']);
        if ($person === null || $document === null || ! in_array((string) $document->kind, ['pdf', 'image'], true) || ! AiConsent::allows($subjectId)) {
            return $skip();
        }
        $decided = DB::table('medical_ai_extractions as e')->join('medical_ai_decisions as x', 'x.extraction_id', '=', 'e.id')
            ->where('e.document_id', $docId)->where('x.decision', 'accepted')->exists();
        if ($decided) {
            return $skip();
        }

        $id = (string) Str::uuid();
        try {
            // A savepoint: a lost race on one_active must not poison the surrounding tick transaction.
            DB::transaction(fn () => DB::table('medical_ai_extractions')->insert([
                'id' => $id, 'subject_id' => $subjectId, 'document_id' => $docId, 'status' => 'queued', 'agent' => $agentKey,
                'model' => null, 'schema_version' => ExtractionSchema::SCHEMA_VERSION, 'prompt_version' => ExtractionSchema::PROMPT_VERSION,
                'started_by' => (int) $run->started_by, 'bulk_run_id' => $runId, 'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return $skip();
            }
            throw $e;
        }
        DB::table('ai_calls')->insert([
            'extraction_id' => $id, 'subject_id' => $subjectId, 'document_id' => $docId, 'started_by' => (int) $run->started_by,
            'agent' => $agentKey, 'model' => null, 'attempt' => 1, 'status' => 'queued', 'queued_at' => $now, 'bulk_run_id' => $runId,
        ]);
        DB::table('ai_bulk_run_items')->insert([
            'bulk_run_id' => $runId, 'document_id' => $docId, 'extraction_id' => $id, 'enqueued_at' => $now,
        ]);
        $actor = User::query()->find((int) $run->started_by);
        $this->audit->record(AuditEvent::MedicalAiRunRequested, $actor, AiRunAudit::meta($subjectId, $docId, $runId), null, Module::Health);
        DB::table('ai_bulk_runs')->where('id', $runId)->update(['last_enqueued_at' => $now, 'blocked_since' => null, 'updated_at' => $now]);

        return $id;
    }

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
                $this->audit->record(AuditEvent::MedicalAiRunFinished, $actor, AiRunAudit::meta((string) $row->subject_id, (string) $row->document_id, $runId, 'cancelled'), null, Module::Health);
            }

            $count += DB::table('medical_ai_extractions')->where('bulk_run_id', $runId)->where('status', 'running')
                ->whereNull('cancel_requested_at')->update(['cancel_requested_at' => $now, 'updated_at' => $now]);

            return $count;
        });
    }
}
