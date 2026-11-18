<?php

namespace App\Medical\Queries;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\AiConsent;
use App\Medical\Documents\MedicalDocumentStore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Unscoped by design: the system side of upload batches (scheduler, worker, consent hook), no viewer. Ids, enums and
 * counts only; nothing here reads or returns a title, a file name or recognised text. Paths are collected only to be
 * deleted and never leave this class.
 */
final class UploadBatchJobQuery
{
    private const ACTIVE = ['queued', 'running', 'waiting'];

    private const NEUTRAL_ADJUSTMENTS = ['brightness' => 100, 'contrast' => 100, 'bw' => false];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AgentRegistry $agents,
        private readonly AiJobQuery $jobs,
        private readonly SystemQuery $system,
        private readonly AccessGate $gate,
        private readonly MedicalDocumentStore $store,
    ) {}

    /** Ids of the batches that are open, oldest first. @return list<string> */
    public function openBatchIds(): array
    {
        return DB::table('medical_upload_batches')->where('status', 'open')->orderBy('created_at')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Starts the batch's next document, if any may start now. One transaction under a per-batch advisory lock; a batch
     * never has more than one queued, running or waiting extraction (a run parked on quota counts, so the batch stands
     * still until the existing resume path requeues it). Returns the extraction id for the caller to dispatch after commit.
     */
    public function advance(string $batchId, CarbonImmutable $now): ?string
    {
        if (! Str::isUuid($batchId)) {
            return null;
        }

        return DB::transaction(function () use ($batchId, $now): ?string {
            DB::select("select pg_advisory_xact_lock(hashtext('upload-batch:'||?::text))", [$batchId]);
            $batch = DB::table('medical_upload_batches')->where('id', $batchId)->where('status', 'open')->lockForUpdate()->first(['id', 'subject_id', 'acted_by']);
            if ($batch === null) {
                return null;
            }
            if (DB::table('medical_ai_extractions')->where('upload_batch_id', $batchId)->whereIn('status', self::ACTIVE)->exists()) {
                return null;
            }

            $subjectId = (string) $batch->subject_id;
            // A concurrent consent change takes FOR UPDATE on this row and so serializes with the share lock.
            $person = DB::table('medical_subjects')->where('id', $subjectId)->whereNull('archived_at')->sharedLock()->first(['id']);
            if ($person === null || ! AiConsent::allows($subjectId)) {
                return null;
            }

            // A re-scan goes first, oldest request first; otherwise upload order.
            $item = DB::table('medical_upload_batch_items as i')->join('medical_documents as d', 'd.id', '=', 'i.document_id')
                ->where('i.batch_id', $batchId)->where('i.state', 'pending')->whereNull('d.archived_at')
                ->orderByRaw('i.rescan_requested_at asc nulls last')->orderBy('i.position')
                ->first(['i.id', 'i.document_id', 'i.requested_by', 'i.rescan_requested_at', 'i.adjustments']);
            if ($item === null) {
                return null;
            }

            $starterId = $item->requested_by ?? $batch->acted_by;
            $starter = $starterId === null ? null : User::query()->find((int) $starterId);
            if ($starter === null) {
                return null;
            }
            // Long-lived process: never trust a cached grant.
            $this->gate->forget($starter);
            if (! $this->gate->allows($starter, Module::Health, AccessLevel::Edit) || ! $this->system->isEditor($subjectId, $starter->id)) {
                return null;
            }

            // The same serialization as an interactive start, so the global bound holds under concurrent starts.
            DB::select("select pg_advisory_xact_lock(hashtext('ai-run-start'))");
            if (DB::table('medical_ai_extractions')->whereIn('status', self::ACTIVE)->count() >= (int) config('ai.limits.max_active_global')) {
                return null;
            }
            $agent = $this->agents->candidates([], false, $this->jobs->ackedProviders($starter->id))[0] ?? null;
            if ($agent === null) {
                return null;
            }

            $adjustments = json_decode((string) $item->adjustments, true);
            $adjusted = is_array($adjustments) && $adjustments != self::NEUTRAL_ADJUSTMENTS;
            $docId = (string) $item->document_id;
            $id = (string) Str::uuid();
            try {
                // A savepoint: a lost race on one_active must not poison the surrounding transaction.
                DB::transaction(fn () => DB::table('medical_ai_extractions')->insert([
                    'id' => $id, 'subject_id' => $subjectId, 'document_id' => $docId, 'status' => 'queued', 'agent' => $agent->key(),
                    'model' => null, 'schema_version' => ExtractionSchema::SCHEMA_VERSION, 'prompt_version' => ExtractionSchema::PROMPT_VERSION,
                    'mode' => config('ai.ocr.enabled') ? 'full' : 'entries', 'started_by' => $starter->id,
                    'upload_batch_id' => $batchId, 'fresh_read' => $item->rescan_requested_at !== null,
                    'adjustments' => $adjusted ? json_encode($adjustments) : null,
                    'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    return null;
                }
                throw $e;
            }
            DB::table('ai_calls')->insert([
                'extraction_id' => $id, 'subject_id' => $subjectId, 'document_id' => $docId, 'started_by' => $starter->id,
                'agent' => $agent->key(), 'model' => null, 'attempt' => 1, 'status' => 'queued', 'queued_at' => $now,
            ]);
            DB::table('medical_upload_batch_items')->where('id', $item->id)->update([
                'state' => 'sent', 'extraction_id' => $id, 'rescan_requested_at' => null, 'updated_at' => $now,
            ]);
            $this->audit->record(AuditEvent::MedicalAiRunRequested, $starter, AiRunAudit::meta($subjectId, $docId, null), null, Module::Health);

            return $id;
        });
    }

    /**
     * Stops the batch's runs: queued and waiting extractions become cancelled(user) at once (call rows closed, one audit
     * row each), a running one is asked to stop at its next poll. With $documentId only that document's run. Returns how
     * many extractions that touched.
     */
    public function cancelBatchRuns(string $batchId, ?int $actorId, ?string $documentId = null): int
    {
        return $this->cancelRuns($batchId, $actorId, $documentId, 'user');
    }

    /**
     * Deletes the batch with its unfinished documents and their stored files. A document that already has an accepted AI
     * decision is kept as an ordinary document (released from the batch first); documents finished with Continue no
     * longer reference the batch and are untouched. $action is 'deleted' or 'expired'. Returns how many documents went.
     */
    public function purge(string $batchId, ?int $actorId, string $action): int
    {
        if (! Str::isUuid($batchId)) {
            return 0;
        }
        if (! in_array($action, ['deleted', 'expired'], true)) {
            throw new InvalidArgumentException('Unknown purge action.');
        }
        $paths = [];

        $removed = DB::transaction(function () use ($batchId, $actorId, $action, &$paths): int {
            DB::select("select pg_advisory_xact_lock(hashtext('upload-batch:'||?::text))", [$batchId]);
            $batch = DB::table('medical_upload_batches')->where('id', $batchId)->lockForUpdate()->first(['id', 'subject_id']);
            if ($batch === null) {
                return 0;
            }
            $subjectId = (string) $batch->subject_id;
            $this->cancelRuns($batchId, $actorId, null, 'user');

            // An accepted decision is work the member did: the document stays, as an ordinary one.
            DB::table('medical_documents as d')->where('d.upload_batch_id', $batchId)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('medical_ai_extractions as e')
                    ->join('medical_ai_decisions as x', 'x.extraction_id', '=', 'e.id')
                    ->whereColumn('e.document_id', 'd.id')->where('x.decision', 'accepted'))
                ->update(['upload_batch_id' => null]);

            $rows = DB::table('medical_documents')->where('upload_batch_id', $batchId)->get(['path', 'thumb_path']);
            foreach ($rows as $row) {
                foreach ([$row->path, $row->thumb_path] as $path) {
                    if ($path !== null && $path !== '') {
                        $paths[] = (string) $path;
                    }
                }
            }
            // The foreign keys cascade: items, and the pending documents with their extractions and texts.
            DB::table('medical_upload_batches')->where('id', $batchId)->delete();
            $count = $rows->count();

            $actor = $actorId === null ? null : User::query()->find($actorId);
            $this->audit->record(AuditEvent::MedicalUploadBatchChanged, $actor, [
                'subject' => $subjectId, 'batch' => $batchId, 'action' => $action, 'count' => $count,
            ], null, Module::Health);

            return $count;
        });

        // Explicit deletion (prune-orphans has a circuit breaker and is only the backstop); after commit so a rollback keeps files.
        if ($paths !== []) {
            DB::afterCommit(function () use ($paths): void {
                foreach ($paths as $path) {
                    try {
                        $this->store->delete([$path]);
                    } catch (InvalidArgumentException) {
                    }
                }
            });
        }

        return $removed;
    }

    /**
     * Consent was just withdrawn for the person (called inside the consent transaction): the person's open-batch runs
     * stop (queued and waiting at once, a running one at its next check) and their documents go back to pending, so the
     * batch resumes by itself when consent is turned on again. The batch, its documents and saved choices stay.
     */
    public function resetForConsent(string $subjectId): void
    {
        if (! Str::isUuid($subjectId)) {
            return;
        }
        foreach (DB::table('medical_upload_batches')->where('subject_id', $subjectId)->where('status', 'open')->pluck('id') as $batchId) {
            $batchId = (string) $batchId;
            $runIds = DB::table('medical_ai_extractions')->where('upload_batch_id', $batchId)->whereIn('status', self::ACTIVE)->pluck('id')->all();
            $this->cancelRuns($batchId, null, null, 'consent');
            if ($runIds !== []) {
                DB::table('medical_upload_batch_items')->where('batch_id', $batchId)->where('state', 'sent')->whereIn('extraction_id', $runIds)
                    ->update(['state' => 'pending', 'extraction_id' => null, 'updated_at' => Carbon::now()]);
            }
        }
    }

    /**
     * Purges every batch past its expiry (open or finished).
     *
     * @return array{expired: int, removed: int}
     */
    public function expireDue(CarbonImmutable $now): array
    {
        $expired = 0;
        $removed = 0;
        foreach (DB::table('medical_upload_batches')->where('expires_at', '<=', $now)->orderBy('expires_at')->pluck('id') as $id) {
            $removed += $this->purge((string) $id, null, 'expired');
            $expired++;
        }

        return ['expired' => $expired, 'removed' => $removed];
    }

    private function cancelRuns(string $batchId, ?int $actorId, ?string $documentId, string $reason): int
    {
        if (! Str::isUuid($batchId) || ($documentId !== null && ! Str::isUuid($documentId))) {
            return 0;
        }
        $actor = $actorId === null ? null : User::query()->find($actorId);

        return DB::transaction(function () use ($batchId, $documentId, $reason, $actor): int {
            $now = Carbon::now();
            $count = 0;
            $scope = fn () => DB::table('medical_ai_extractions')->where('upload_batch_id', $batchId)
                ->when($documentId !== null, fn ($q) => $q->where('document_id', $documentId));

            $open = $scope()->whereIn('status', ['queued', 'waiting'])->lockForUpdate()->get(['id', 'subject_id', 'document_id', 'status']);
            foreach ($open as $row) {
                // available_at goes in the same UPDATE (CHECK: set exactly while waiting); waiting_since stays as history.
                $changed = DB::table('medical_ai_extractions')->where('id', $row->id)->where('status', $row->status)->update([
                    'status' => 'cancelled', 'cancel_reason' => $reason, 'model' => 'default', 'available_at' => null,
                    'finished_at' => $now, 'updated_at' => $now,
                ]);
                if ($changed === 0) {
                    continue;
                }
                $count++;
                DB::table('ai_calls')->where('extraction_id', $row->id)->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'cancelled', 'model' => 'default', 'finished_at' => $now,
                ]);
                $this->audit->record(AuditEvent::MedicalAiRunFinished, $actor, AiRunAudit::meta((string) $row->subject_id, (string) $row->document_id, null, 'cancelled'), null, Module::Health);
            }

            return $count + $scope()->where('status', 'running')->whereNull('cancel_requested_at')
                ->update(['cancel_requested_at' => $now, 'updated_at' => $now]);
        });
    }
}
