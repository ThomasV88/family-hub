<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\StartResult;
use App\Medical\AiConsent;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The request side of an AI run: start, cancel and the status map. Every statement on the proposal table carries the
 * viewer's membership scope. Nothing here reads proposal content; only ids, enums and counts leave this class.
 */
class AiRunQuery
{
    private const ACTIVE = ['queued', 'running'];

    private const QUOTA_FAILURES = ['timeout', 'idle_timeout', 'no_output', 'invalid_output', 'output_too_large', 'cli_error', 'auth'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentQuery $documents,
        private readonly AgentHealth $health,
        private readonly AgentRegistry $agents,
    ) {}

    /** Whether this member has already acknowledged the one-time "document leaves the server" notice. */
    public function noticeAcknowledged(Viewer $viewer): bool
    {
        return DB::table('ai_notice_acks')->where('user_id', $viewer->userId())->exists();
    }

    /**
     * One transaction: the subject row is locked FOR SHARE through the Edit scope (a concurrent consent change takes
     * FOR UPDATE and so serializes with this), then document kind, consent, availability and notice are checked, and the
     * audit row, the queued extraction, the call-log row and the acknowledgment are written together. The caller
     * dispatches the job after commit.
     */
    public function start(Viewer $viewer, MedicalSubject $subject, string $documentId, User $user, bool $acknowledged): StartResult
    {
        if (! $viewer->canEdit()) {
            return new StartResult('denied');
        }
        $subjectId = (string) $subject->getKey();

        $raced = false;

        try {
            return DB::transaction(function () use (&$raced, $viewer, $subject, $subjectId, $documentId, $user, $acknowledged): StartResult {
                $locked = Visibility::apply(
                    DB::table('medical_subjects')->where('id', $subjectId)->sharedLock(),
                    $viewer,
                    'id',
                    AccessLevel::Edit,
                )->first(['id']);
                if ($locked === null) {
                    return new StartResult('denied');
                }
                $document = $this->documents->find($viewer, $subject, $documentId, AccessLevel::Edit);
                if ($document === null) {
                    return new StartResult('denied');
                }
                if (! in_array((string) $document->kind, ['pdf', 'image'], true)) {
                    return new StartResult('unsupported');
                }
                if (! AiConsent::allows($subjectId)) {
                    return new StartResult('consent_off');
                }
                if (! $this->health->runnable()) {
                    return new StartResult('unavailable');
                }
                $acked = $this->noticeAcknowledged($viewer);
                if (! $acked && ! $acknowledged) {
                    return new StartResult('notice_required');
                }
                $active = $this->activeId($viewer, $subjectId, $documentId);
                if ($active !== null) {
                    return new StartResult('active', $active);
                }

                if ($this->busy($viewer, $documentId)) {
                    return new StartResult('busy');
                }

                $this->audit->record(AuditEvent::MedicalAiRunRequested, $user, ['subject' => $subjectId, 'document' => $documentId], null, Module::Health);

                $id = (string) Str::uuid();
                $now = Carbon::now();
                try {
                    DB::transaction(fn () => DB::table('medical_ai_extractions')->insert([
                        'id' => $id,
                        'subject_id' => $subjectId,
                        'document_id' => $documentId,
                        'status' => 'queued',
                        'agent' => $this->agents->primary()->key(),
                        'model' => null,
                        'schema_version' => ExtractionSchema::SCHEMA_VERSION,
                        'prompt_version' => ExtractionSchema::PROMPT_VERSION,
                        'started_by' => $viewer->userId(),
                        'queued_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) === '23505') {
                        // Lost a race against another start: roll the audit row back too and report the winner.
                        $raced = true;

                        throw new RuntimeException('active run exists');
                    }
                    throw $e;
                }
                DB::table('ai_calls')->insert([
                    'extraction_id' => $id,
                    'subject_id' => $subjectId,
                    'document_id' => $documentId,
                    'started_by' => $viewer->userId(),
                    'agent' => $this->agents->primary()->key(),
                    'model' => null,
                    'attempt' => 1,
                    'status' => 'queued',
                    'queued_at' => $now,
                ]);
                if (! $acked) {
                    DB::table('ai_notice_acks')->insertOrIgnore(['user_id' => $viewer->userId(), 'acknowledged_at' => $now]);
                }

                return new StartResult('queued', $id);
            });
        } catch (RuntimeException $e) {
            if (! $raced) {
                throw $e;
            }

            return new StartResult('active', $this->activeId($viewer, $subjectId, $documentId));
        }
    }

    /**
     * Null when the extraction is outside the viewer's Edit scope (the controller answers 403). A queued run is
     * cancelled at once, a running one is asked to stop, anything else is reported as it is.
     *
     * @return array{status: string}|null
     */
    public function cancel(Viewer $viewer, MedicalSubject $subject, string $extractionId): ?array
    {
        if (! $viewer->canEdit() || ! Str::isUuid($extractionId)) {
            return null;
        }
        $user = $viewer->user;

        return DB::transaction(function () use ($viewer, $subject, $extractionId, $user) {
            $scoped = fn () => Visibility::apply(
                DB::table('medical_ai_extractions')->where('id', $extractionId)->where('subject_id', $subject->getKey()),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            );
            $row = $scoped()->lockForUpdate()->first(['status', 'document_id', 'subject_id']);
            if ($row === null) {
                return null;
            }
            $now = Carbon::now();

            if ($row->status === 'queued') {
                $changed = $scoped()->where('status', 'queued')->update([
                    'status' => 'cancelled', 'cancel_reason' => 'user', 'model' => 'default', 'finished_at' => $now, 'updated_at' => $now,
                ]);
                if ($changed > 0) {
                    DB::table('ai_calls')->where('extraction_id', $extractionId)->where('status', 'queued')->update([
                        'status' => 'cancelled', 'model' => 'default', 'finished_at' => $now,
                    ]);
                    $this->audit->record(AuditEvent::MedicalAiRunFinished, $user, [
                        'subject' => (string) $row->subject_id, 'document' => (string) $row->document_id, 'status' => 'cancelled',
                    ], null, Module::Health);

                    return ['status' => 'cancelled'];
                }
            }
            if ($row->status === 'running') {
                $scoped()->where('status', 'running')->whereNull('cancel_requested_at')
                    ->update(['cancel_requested_at' => $now, 'updated_at' => $now]);

                return ['status' => 'running'];
            }

            return ['status' => (string) $row->status];
        });
    }

    /**
     * Per document the newest non-superseded run. Counts go to Edit viewers only.
     *
     * @return array<string, array<string, mixed>>
     */
    public function statusMap(Viewer $viewer, MedicalSubject $subject): array
    {
        $withCounts = $viewer->canEdit() && Visibility::apply(
            DB::table('medical_subjects')->where('id', $subject->getKey()),
            $viewer,
            'id',
            AccessLevel::Edit,
        )->exists();
        $query = Visibility::apply(
            DB::table('medical_ai_extractions')
                ->where('subject_id', $subject->getKey())
                ->where('status', '!=', 'superseded'),
            $viewer,
        )->orderByDesc('queued_at')->orderByDesc('id')
            ->select(['id', 'document_id', 'status', 'cancel_reason', 'pages_read', 'pages_total', 'entry_count']);
        if ($withCounts) {
            $query->selectSub(
                DB::table('medical_ai_decisions')->selectRaw('count(*)')->whereColumn('medical_ai_decisions.extraction_id', 'medical_ai_extractions.id'),
                'decided',
            );
        }
        $rows = $query->get();

        $map = [];
        foreach ($rows as $row) {
            $doc = (string) $row->document_id;
            if (! isset($map[$doc])) {
                $map[$doc] = [
                    'status' => (string) $row->status,
                    'extractionId' => (string) $row->id,
                    'cancelReason' => $row->cancel_reason,
                    'pagesRead' => $row->pages_read === null ? null : (int) $row->pages_read,
                    'pagesTotal' => $row->pages_total === null ? null : (int) $row->pages_total,
                    'readyExtractionId' => null,
                ];
            }
            if ($row->status === 'ready') {
                $map[$doc]['readyExtractionId'] = (string) $row->id;
                if ($withCounts) {
                    $count = (int) $row->entry_count;
                    $map[$doc]['entryCount'] = $count;
                    $map[$doc]['pending'] = max(0, $count - (int) $row->decided);
                }
            }
        }

        return $map;
    }

    /**
     * The quota guard: a cap on active runs per starter, a global cap, and a cool-down after the document's last
     * run that used quota (ready, or failed after the CLI ran). A transaction-scoped advisory lock serializes the counting across people, so the bounds hold under
     * concurrent starts. Cancelled runs do not start a cool-down.
     */
    private function busy(Viewer $viewer, string $documentId): bool
    {
        DB::select("select pg_advisory_xact_lock(hashtext('ai-run-start'))");

        $active = fn () => DB::table('medical_ai_extractions')->whereIn('status', self::ACTIVE);
        if ($active()->count() >= (int) config('ai.limits.max_active_global')) {
            return true;
        }
        if ($active()->where('started_by', $viewer->userId())->count() >= (int) config('ai.limits.max_active_per_user')) {
            return true;
        }
        $cooldown = (int) config('ai.limits.document_cooldown_minutes');

        return $cooldown > 0 && DB::table('medical_ai_extractions')
            ->where('document_id', $documentId)
            ->where('finished_at', '>', Carbon::now()->subMinutes($cooldown))
            ->where(fn ($q) => $q->whereIn('status', ['ready', 'superseded'])
                // Only failures after the CLI really ran used quota; a lost worker, a bad file or a dispatch failure did not.
                ->orWhere(fn ($f) => $f->where('status', 'failed')->whereIn('failure_code', self::QUOTA_FAILURES)))
            ->exists();
    }

    /** The queued or running extraction of the document, inside the viewer's scope. */
    private function activeId(Viewer $viewer, string $subjectId, string $documentId): ?string
    {
        $id = Visibility::apply(
            DB::table('medical_ai_extractions')->where('document_id', $documentId)->where('subject_id', $subjectId)->whereIn('status', self::ACTIVE),
            $viewer,
        )->value('id');

        return $id === null ? null : (string) $id;
    }
}
