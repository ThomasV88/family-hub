<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
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
    private const ACTIVE = ['queued', 'running', 'waiting'];

    private const QUOTA_FAILURES = ['timeout', 'idle_timeout', 'no_output', 'invalid_output', 'output_too_large', 'cli_error', 'auth'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentQuery $documents,
        private readonly AgentRegistry $agents,
    ) {}

    /**
     * Whether this member has already acknowledged the "document leaves the server" notice for every enabled provider.
     * Enabling a further provider asks again once; removing one never does. $enabled lets a caller that already read
     * the set under the settings lock reuse it.
     *
     * @param  list<string>|null  $enabled
     */
    public function noticeAcknowledged(Viewer $viewer, ?array $enabled = null): bool
    {
        $providers = DB::table('ai_notice_acks')->where('user_id', $viewer->userId())->value('providers');
        if ($providers === null) {
            return false;
        }
        $acked = explode(',', (string) $providers);

        return array_diff($enabled ?? $this->agents->providers(), $acked) === [];
    }

    /** @return list<string> the enabled provider keys, in saved order */
    public function providers(): array
    {
        return array_values($this->agents->providers());
    }

    /** Run AI is offered while at least one enabled agent has a usable login; cooling down still counts. */
    public function available(): bool
    {
        return $this->agents->anyRunnable();
    }

    /**
     * One transaction: the subject row is locked FOR SHARE through the Edit scope (a concurrent consent change takes
     * FOR UPDATE and so serializes with this), then document kind, consent, availability and notice are checked, and the
     * audit row, the queued extraction, the call-log row and the acknowledgment are written together. The caller
     * dispatches the job after commit.
     *
     * The acknowledgment names the provider set the member saw ($shownProviders). It is stored only when that set equals
     * the enabled set read under a shared lock that provider changes take exclusively; otherwise the answer is
     * 'notice_required' with the current set, so the dialog opens again with what is true now.
     *
     * @param  list<string>|null  $shownProviders
     */
    public function start(Viewer $viewer, MedicalSubject $subject, string $documentId, User $user, bool $acknowledged, ?array $shownProviders = null): StartResult
    {
        if (! $viewer->canEdit()) {
            return new StartResult('denied');
        }
        $subjectId = (string) $subject->getKey();

        $raced = false;

        try {
            return DB::transaction(function () use (&$raced, $viewer, $subject, $subjectId, $documentId, $user, $acknowledged, $shownProviders): StartResult {
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
                if (! $this->agents->anyRunnable()) {
                    return new StartResult('unavailable');
                }
                // Enabling or disabling a provider takes this lock exclusively, so the set below cannot change before commit.
                DB::select("select pg_advisory_xact_lock_shared(hashtext('ai-agent-settings'))");
                $enabledKeys = $this->agents->providers();
                $acked = $this->noticeAcknowledged($viewer, $enabledKeys);
                if (! $acked && (! $acknowledged || ! self::sameSet($shownProviders, $enabledKeys))) {
                    return new StartResult('notice_required', null, array_values($enabledKeys));
                }
                $active = $this->activeId($viewer, $subjectId, $documentId);
                if ($active !== null) {
                    return new StartResult('active', $active);
                }

                if ($this->busy($viewer, $documentId)) {
                    return new StartResult('busy');
                }

                $first = $this->agents->candidates([], false, $enabledKeys)[0] ?? $this->agents->enabled()[0] ?? $this->agents->primary();
                $this->audit->record(AuditEvent::MedicalAiRunRequested, $user, ['subject' => $subjectId, 'document' => $documentId], null, Module::Health);

                $id = (string) Str::uuid();
                $now = Carbon::now();
                try {
                    DB::transaction(fn () => DB::table('medical_ai_extractions')->insert([
                        'id' => $id,
                        'subject_id' => $subjectId,
                        'document_id' => $documentId,
                        'status' => 'queued',
                        'agent' => $first->key(),
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
                    'agent' => $first->key(),
                    'model' => null,
                    'attempt' => 1,
                    'status' => 'queued',
                    'queued_at' => $now,
                ]);
                if (! $acked) {
                    $set = $enabledKeys;
                    sort($set);
                    DB::table('ai_notice_acks')->upsert(
                        [['user_id' => $viewer->userId(), 'acknowledged_at' => $now, 'providers' => implode(',', $set)]],
                        ['user_id'],
                        ['acknowledged_at', 'providers'],
                    );
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

            if (in_array($row->status, ['queued', 'waiting'], true)) {
                $changed = $scoped()->where('status', $row->status)->update([
                    'status' => 'cancelled', 'cancel_reason' => 'user', 'model' => 'default', 'finished_at' => $now, 'updated_at' => $now,
                    'available_at' => null,
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
            ->select(['id', 'document_id', 'status', 'cancel_reason', 'available_at', 'failure_code', 'agent', 'model', 'pages_read', 'pages_total', 'entry_count']);
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
                if ($row->status === 'waiting') {
                    $map[$doc]['availableAt'] = Carbon::parse($row->available_at)->toIso8601String();
                }
                if ($row->status === 'failed') {
                    $map[$doc]['failureCode'] = (string) $row->failure_code;
                }
                if ($row->status === 'ready') {
                    $map[$doc]['agent'] = (string) $row->agent;
                    $map[$doc]['model'] = ($row->model === null || $row->model === 'default') ? null : (string) $row->model;
                }
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
        // Bulk rows do not use up the starter's personal limit; the global limit above still counts them.
        if ($active()->whereNull('bulk_run_id')->where('started_by', $viewer->userId())->count() >= (int) config('ai.limits.max_active_per_user')) {
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

    /**
     * @param  list<string>|null  $shown
     * @param  list<string>  $enabled
     */
    private static function sameSet(?array $shown, array $enabled): bool
    {
        if ($shown === null) {
            return false;
        }
        $a = array_values(array_unique(array_map('strval', $shown)));
        $b = array_values(array_unique($enabled));
        sort($a);
        sort($b);

        return $a === $b;
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
