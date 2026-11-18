<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Requests\Health\AllergyRequest;
use App\Http\Requests\Health\MedicationRequest;
use App\Http\Requests\Health\VaccinationRequest;
use App\Http\Requests\Health\VisitRequest;
use App\Medical\Ai\ProposalEntries;
use App\Medical\AiConsent;
use App\Medical\ArchiveState;
use App\Medical\Batches\ItemState;
use App\Medical\Documents\Adjustments;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The member side of upload batches (Phase 15). Every statement is scoped to the person through Visibility at
 * AccessLevel::Edit, and a batch or item that is hidden, of another person, malformed or unknown answers null (the
 * controller answers 403). Choices and adjustments are stored on the server the moment they change. Nothing here logs,
 * and nothing returns recognised text; titles leave only as page props; audit meta is ids, action and counts.
 *
 * The caller kicks the batch (BatchKicker::kick) after commit following addDocument, requestRescan, continue and removeItem.
 */
final class UploadBatchQuery
{
    private const ACTIVE = ['queued', 'running', 'waiting'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AiRunQuery $runs,
        private readonly DocumentQuery $documents,
        private readonly AiReviewQuery $reviews,
        private readonly UploadBatchJobQuery $jobs,
        private readonly MedicalDocumentStore $store,
    ) {}

    // ---- create / upload -----------------------------------------------------------------------------------------

    /**
     * Starts the person's batch, or returns the open one. Consent, availability and the "document leaves the server"
     * notice are checked exactly like Run AI (the notice helpers are shared with AiRunQuery::start).
     *
     * @param  list<string>|null  $shownProviders
     * @return array{result: 'created'|'exists'|'consent_off'|'unavailable'|'notice_required'|'denied', batchId?: string, providers?: list<string>}
     */
    public function create(Viewer $viewer, MedicalSubject $subject, User $user, bool $acknowledged, ?array $shownProviders = null, bool $textNotice = false): array
    {
        if (! $viewer->canEdit()) {
            return ['result' => 'denied'];
        }
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $subjectId, $user, $acknowledged, $shownProviders, $textNotice): array {
            // A concurrent consent change takes FOR UPDATE on this row and so serializes with the share lock.
            $locked = Visibility::apply(DB::table('medical_subjects')->where('id', $subjectId)->sharedLock(), $viewer, 'id', AccessLevel::Edit)->first(['id']);
            if ($locked === null) {
                return ['result' => 'denied'];
            }
            $open = $this->openBatchId($viewer, $subjectId);
            if ($open !== null) {
                return ['result' => 'exists', 'batchId' => $open];
            }
            if (! AiConsent::allows($subjectId)) {
                return ['result' => 'consent_off'];
            }
            if (! $this->runs->available()) {
                return ['result' => 'unavailable'];
            }
            $needed = $this->runs->acknowledgmentNeeded($viewer, $acknowledged, $shownProviders, $textNotice);
            if ($needed !== null) {
                return ['result' => 'notice_required', 'providers' => $needed];
            }
            $this->acknowledge($viewer);

            $id = (string) Str::uuid();
            $now = Carbon::now();
            try {
                // A savepoint: a lost race on the one-open-batch index must not poison the surrounding transaction.
                DB::transaction(fn () => DB::table('medical_upload_batches')->insert([
                    'id' => $id, 'subject_id' => $subjectId, 'created_by' => $viewer->userId(), 'acted_by' => $viewer->userId(),
                    'status' => 'open', 'expires_at' => $now->copy()->addDays((int) config('medical.upload_batches.expire_days')),
                    'created_at' => $now, 'updated_at' => $now,
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    return ['result' => 'exists', 'batchId' => $this->openBatchId($viewer, $subjectId) ?? $id];
                }
                throw $e;
            }
            $this->auditBatch($user, $subjectId, $id, 'created', 0);

            return ['result' => 'created', 'batchId' => $id];
        });
    }

    /**
     * Adds one stored file to the batch as a pending document and item. Null when the batch is outside the Edit scope.
     *
     * @param  array<string, mixed>  $stored  the document store put() result
     * @return array{itemId: string, documentId: string, duplicateOf: ?string}|null
     *
     * @throws DocumentRejected batch_full, duplicate, batch_closed, limit_reached
     */
    public function addDocument(Viewer $viewer, MedicalSubject $subject, string $batchId, array $stored, string $title, User $user): ?array
    {
        if (! $viewer->canEdit() || ! Str::isUuid($batchId)) {
            return null;
        }
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $subject, $subjectId, $batchId, $stored, $title): ?array {
            $batch = $this->locked($viewer, $subject, $batchId, true);
            if ($batch === null) {
                return null;
            }
            if ($batch->status !== 'open') {
                throw new DocumentRejected('batch_closed');
            }
            $items = $this->scopedItems($viewer, $subject, $batchId);
            if ((clone $items)->count() >= (int) config('medical.upload_batches.max_files')) {
                throw new DocumentRejected('batch_full');
            }
            $sha = (string) $stored['sha256'];
            $sameInBatch = DB::table('medical_upload_batch_items as i')->join('medical_documents as d', 'd.id', '=', 'i.document_id')
                ->where('i.batch_id', $batchId)->where('d.sha256', $sha)->exists();
            if ($sameInBatch) {
                throw new DocumentRejected('duplicate');
            }
            $duplicateOf = Visibility::apply(DB::table('medical_documents')->where('subject_id', $subjectId)->whereNull('upload_batch_id')->where('sha256', $sha), $viewer, 'subject_id', AccessLevel::Edit, ArchiveState::Any)
                ->orderBy('created_at')->value('id');

            $document = $this->documents->create($viewer, $subject, $stored, $title, null, $batchId);
            $itemId = (string) Str::uuid();
            $now = Carbon::now();
            DB::table('medical_upload_batch_items')->insert([
                'id' => $itemId, 'batch_id' => $batchId, 'document_id' => (string) $document->getKey(),
                'position' => (int) (clone $items)->max('position') + 1, 'state' => 'pending',
                'duplicate_of' => $duplicateOf === null ? null : (string) $duplicateOf,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->update(['acted_by' => $viewer->userId(), 'updated_at' => $now]);

            return ['itemId' => $itemId, 'documentId' => (string) $document->getKey(), 'duplicateOf' => $duplicateOf === null ? null : (string) $duplicateOf];
        });
    }

    // ---- read ----------------------------------------------------------------------------------------------------

    /**
     * The batch page props. Null outside the Edit scope.
     *
     * @return array<string, mixed>|null
     */
    public function page(Viewer $viewer, MedicalSubject $subject, string $batchId): ?array
    {
        $batch = $this->locked($viewer, $subject, $batchId, false);
        if ($batch === null) {
            return null;
        }
        $subjectId = (string) $subject->getKey();
        $person = Visibility::apply(DB::table('medical_subjects')->where('id', $subjectId), $viewer, 'id', AccessLevel::Edit)->first(['id', 'name', 'ai_consent']);
        if ($person === null) {
            return null;
        }
        $items = $this->items($viewer, $subject, $batchId, (bool) $person->ai_consent);

        $currentId = $this->currentItemId($batch, $items);
        $current = null;
        foreach ($items as $item) {
            if ($item['id'] === $currentId) {
                $current = $item;
            }
        }

        $currentProps = null;
        if ($current !== null) {
            $row = $this->scopedItems($viewer, $subject, $batchId)->where('id', $current['id'])->first(['adjustments', 'choices', 'extraction_id']);
            $review = null;
            $pageCount = null;
            if ($current['state'] === 'review' && $row->extraction_id !== null) {
                $props = $this->reviews->review($viewer, $subject, $current['documentId'], (string) $row->extraction_id);
                if (is_array($props) && ! isset($props['redirect'])) {
                    $review = ['extraction' => $props['extraction'], 'provenance' => $props['provenance'], 'specialties' => $props['specialties']];
                    $pageCount = $props['document']['pageCount'];
                }
            }
            $currentProps = [
                'item' => $current,
                'document' => ['id' => $current['documentId'], 'kind' => $current['kind'], 'title' => $current['title'], 'pageCount' => $pageCount],
                'review' => $review,
                'choices' => (object) $this->decode($row->choices),
                'adjustments' => Adjustments::fromArray($this->decode($row->adjustments))->toArray(),
            ];
        }

        return [
            'batch' => $this->batchProps($batch, $items, $currentId),
            'items' => $items,
            'current' => $currentProps,
            'limits' => [
                'maxFiles' => (int) config('medical.upload_batches.max_files'),
                'maxMb' => (int) round((int) config('medical.document_max_bytes') / 1048576),
                'maxRescans' => (int) config('medical.upload_batches.max_rescans'),
            ],
            'ai' => [
                'available' => $this->runs->available(),
                'providers' => $this->runs->providers(),
                'noticeAcknowledged' => $this->runs->noticeAcknowledged($viewer),
                'textNotice' => (bool) config('ai.ocr.enabled'),
            ],
            'person' => ['id' => $subjectId, 'name' => (string) $person->name, 'aiConsent' => (bool) $person->ai_consent],
        ];
    }

    /**
     * The person's open batch for the person page: ids and counts only. Null for a View member or when none is open.
     *
     * @return array{id: string, total: int, done: int, expiresAt: string}|null
     */
    public function openFor(Viewer $viewer, MedicalSubject $subject): ?array
    {
        if (! $viewer->canEdit()) {
            return null;
        }
        $batch = Visibility::apply(DB::table('medical_upload_batches')->where('subject_id', $subject->getKey())->where('status', 'open'), $viewer, 'subject_id', AccessLevel::Edit)
            ->first(['id', 'expires_at']);
        if ($batch === null) {
            return null;
        }
        $items = $this->scopedItems($viewer, $subject, (string) $batch->id);

        return [
            'id' => (string) $batch->id,
            'total' => (clone $items)->count(),
            'done' => (clone $items)->where('state', 'done')->count(),
            'expiresAt' => Carbon::parse($batch->expires_at)->toIso8601String(),
        ];
    }

    /**
     * The polling payload: counts and items, never recognised text. Null outside the Edit scope.
     *
     * @return array<string, mixed>|null
     */
    public function status(Viewer $viewer, MedicalSubject $subject, string $batchId): ?array
    {
        $batch = $this->locked($viewer, $subject, $batchId, false);
        if ($batch === null) {
            return null;
        }
        $consent = (bool) Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->value('ai_consent');
        $items = $this->items($viewer, $subject, $batchId, $consent);

        return ['batch' => $this->batchProps($batch, $items, $this->currentItemId($batch, $items)), 'items' => $items];
    }

    // ---- persisted member choices --------------------------------------------------------------------------------

    /** True when the item belongs to this batch (the cursor is stored), false when it does not, null outside the scope. */
    public function setCurrent(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId): ?bool
    {
        return DB::transaction(function () use ($viewer, $subject, $batchId, $itemId): ?bool {
            if ($this->locked($viewer, $subject, $batchId, true) === null) {
                return null;
            }
            if ($this->item($batchId, $itemId, false) === null) {
                return false;
            }
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->update(['current_item_id' => $itemId, 'updated_at' => Carbon::now()]);

            return true;
        });
    }

    /**
     * Replaces the item's approve/reject choices. Only keys of the ready proposal, only the entry type's fields, values
     * trimmed and cut. Null outside the scope or for an unknown item; 'not_ready' when there is no ready proposal to
     * choose from (also for a finished item); otherwise 'saved' with the stored choices.
     *
     * @param  array<string, mixed>  $choices
     * @return array{result: 'saved'|'not_ready', choices?: array<string, mixed>}|null
     */
    public function saveChoices(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId, array $choices): ?array
    {
        return DB::transaction(function () use ($viewer, $subject, $batchId, $itemId, $choices): ?array {
            if ($this->locked($viewer, $subject, $batchId, true) === null) {
                return null;
            }
            $item = $this->item($batchId, $itemId, true);
            if ($item === null) {
                return null;
            }
            $payload = $this->readyPayload($viewer, $subject, $item);
            if ($item->state === 'done' || $payload === null || $item->rescan_requested_at !== null) {
                return ['result' => 'not_ready'];
            }
            $max = (int) config('medical.upload_batches.max_choice_value_chars');
            $clean = [];
            foreach ($choices as $key => $choice) {
                $key = (string) $key;
                $entry = ProposalEntries::entry($payload, $key);
                if ($entry === null || ! is_array($choice) || ! in_array($choice['decision'] ?? null, ['approve', 'reject'], true)) {
                    continue;
                }
                $one = ['decision' => $choice['decision']];
                if ($choice['decision'] === 'approve' && is_array($choice['values'] ?? null)) {
                    $allowed = array_keys(self::rulesFor($entry['type']));
                    $values = [];
                    foreach ($choice['values'] as $field => $value) {
                        if (in_array($field, $allowed, true) && (is_string($value) || is_int($value) || is_float($value))) {
                            $values[$field] = trim(mb_substr(trim((string) $value), 0, $max));
                        }
                    }
                    if ($values !== []) {
                        $one['values'] = $values;
                    }
                }
                $clean[$key] = $one;
            }
            $this->scopedItems($viewer, $subject, $batchId)->where('id', $itemId)->update([
                'choices' => json_encode((object) $clean), 'updated_at' => Carbon::now(),
            ]);

            return ['result' => 'saved', 'choices' => $clean];
        });
    }

    /**
     * Stores the brightness, contrast and black-and-white choice of the item's AI copy (clamped). The file is untouched.
     * Null outside the scope or for a finished item.
     *
     * @param  array<string, mixed>  $values
     * @return array{brightness: int, contrast: int, bw: bool}|null
     */
    public function saveAdjustments(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId, array $values): ?array
    {
        return DB::transaction(function () use ($viewer, $subject, $batchId, $itemId, $values): ?array {
            if ($this->locked($viewer, $subject, $batchId, true) === null) {
                return null;
            }
            $item = $this->item($batchId, $itemId, true);
            if ($item === null || $item->state === 'done') {
                return null;
            }
            $adjustments = Adjustments::fromArray($values)->toArray();
            $this->scopedItems($viewer, $subject, $batchId)->where('id', $itemId)->update([
                'adjustments' => json_encode($adjustments), 'updated_at' => Carbon::now(),
            ]);

            return $adjustments;
        });
    }

    // ---- re-scan -------------------------------------------------------------------------------------------------

    /**
     * Asks for a fresh read of one unfinished document with its saved adjustments. The tick (15-03) starts it: it sees
     * rescan_requested_at and sends the run as a fresh read. Cancels the document's active run, clears its choices.
     *
     * @param  list<string>|null  $shownProviders
     * @return array{result: 'queued'|'limit'|'done'|'consent_off'|'notice_required', providers?: list<string>}|null
     */
    public function requestRescan(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId, User $user, bool $acknowledged, ?array $shownProviders = null, bool $textNotice = false): ?array
    {
        return DB::transaction(function () use ($viewer, $subject, $batchId, $itemId, $user, $acknowledged, $shownProviders, $textNotice): ?array {
            $batch = $this->locked($viewer, $subject, $batchId, true);
            if ($batch === null) {
                return null;
            }
            $item = $this->item($batchId, $itemId, true);
            if ($item === null) {
                return null;
            }
            if ($item->state === 'done' || $batch->status !== 'open') {
                return ['result' => 'done'];
            }
            if ((int) $item->rescan_count >= (int) config('medical.upload_batches.max_rescans')) {
                return ['result' => 'limit'];
            }
            $subjectId = (string) $subject->getKey();
            $locked = Visibility::apply(DB::table('medical_subjects')->where('id', $subjectId)->sharedLock(), $viewer, 'id', AccessLevel::Edit)->first(['id']);
            if ($locked === null) {
                return null;
            }
            if (! AiConsent::allows($subjectId)) {
                return ['result' => 'consent_off'];
            }
            $needed = $this->runs->acknowledgmentNeeded($viewer, $acknowledged, $shownProviders, $textNotice);
            if ($needed !== null) {
                return ['result' => 'notice_required', 'providers' => $needed];
            }
            $this->acknowledge($viewer);

            $this->jobs->cancelBatchRuns($batchId, $viewer->userId(), (string) $item->document_id);
            $now = Carbon::now();
            $this->scopedItems($viewer, $subject, $batchId)->where('id', $itemId)->update([
                'state' => 'pending', 'choices' => '{}', 'rescan_count' => (int) $item->rescan_count + 1,
                'rescan_requested_at' => $now, 'requested_by' => $viewer->userId(), 'updated_at' => $now,
            ]);
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->update(['acted_by' => $viewer->userId(), 'updated_at' => $now]);
            $this->auditBatch($user, $subjectId, $batchId, 'rescan', (int) $item->rescan_count + 1);

            return ['result' => 'queued'];
        });
    }

    // ---- continue ------------------------------------------------------------------------------------------------

    /**
     * Finishes the item: every approved finding becomes a record through the Phase 8 accept path (edited values
     * included), every rejected one is recorded as rejected, undecided ones stay undecided and reviewable on the
     * document's ordinary review page. All or nothing: a finding that fails validation throws ValidationException keyed
     * entries.<entryKey>.<field> and nothing is saved.
     *
     * @return array{result: 'continued'|'not_ready'|'finished', nextItemId: ?string, approved: int, rejected: int}|null
     *
     * @throws ValidationException
     */
    public function continue(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId, User $user): ?array
    {
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $subject, $subjectId, $batchId, $itemId, $user): ?array {
            $batch = $this->locked($viewer, $subject, $batchId, true);
            if ($batch === null) {
                return null;
            }
            $item = $this->item($batchId, $itemId, true);
            if ($item === null) {
                return null;
            }
            if ($item->state === 'done') {
                return ['result' => $batch->status === 'finished' ? 'finished' : 'continued', 'nextItemId' => $this->nextUnfinished($batchId, null), 'approved' => 0, 'rejected' => 0];
            }
            $documentId = (string) $item->document_id;
            // A run in flight, or a re-scan that is about to start, is not finished with.
            $active = DB::table('medical_ai_extractions')->where('document_id', $documentId)->whereIn('status', self::ACTIVE)->exists();
            if ($active || $item->rescan_requested_at !== null) {
                return ['result' => 'not_ready', 'nextItemId' => null, 'approved' => 0, 'rejected' => 0];
            }

            $approved = 0;
            $rejected = 0;
            $extractionId = $item->extraction_id === null ? null : (string) $item->extraction_id;
            $ready = $extractionId !== null && Visibility::apply(
                DB::table('medical_ai_extractions')->where('id', $extractionId)->where('subject_id', $subjectId)->where('status', 'ready'),
                $viewer, 'subject_id', AccessLevel::Edit,
            )->exists();
            if ($ready) {
                foreach ($this->decode($item->choices) as $key => $choice) {
                    $key = (string) $key;
                    if (! is_array($choice)) {
                        continue;
                    }
                    if (($choice['decision'] ?? null) === 'approve') {
                        try {
                            $state = $this->reviews->accept($viewer, $subject, (string) $extractionId, $key, is_array($choice['values'] ?? null) ? $choice['values'] : null, $user);
                        } catch (ValidationException $e) {
                            $messages = [];
                            foreach ($e->errors() as $field => $list) {
                                $messages["entries.{$key}.{$field}"] = $list;
                            }
                            throw ValidationException::withMessages($messages);
                        }
                        $approved += ($state['state'] ?? null) === 'accepted' ? 1 : 0;
                    } elseif (($choice['decision'] ?? null) === 'reject') {
                        $state = $this->reviews->reject($viewer, $subject, (string) $extractionId, $key, $user);
                        $rejected += ($state['state'] ?? null) === 'rejected' ? 1 : 0;
                    }
                }
            }

            // The document is an ordinary one from here on.
            Visibility::apply(DB::table('medical_documents')->where('id', $documentId)->where('subject_id', $subjectId), $viewer, 'subject_id', AccessLevel::Edit)
                ->update(['upload_batch_id' => null, 'updated_at' => Carbon::now()]);
            $now = Carbon::now();
            $this->scopedItems($viewer, $subject, $batchId)->where('id', $itemId)->update(['state' => 'done', 'choices' => '{}', 'updated_at' => $now]);

            $next = $this->nextUnfinished($batchId, (int) $item->position);
            $changes = ['current_item_id' => $next, 'acted_by' => $viewer->userId(), 'updated_at' => $now];
            if ($next === null) {
                $changes += ['status' => 'finished', 'finished_at' => $now];
            }
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->update($changes);
            // The audit meta has one count: the decided findings (each one has its own MedicalAiEntryDecided row, accepted or rejected).
            $this->auditBatch($user, $subjectId, $batchId, 'continued', $approved + $rejected);

            return ['result' => $next === null ? 'finished' : 'continued', 'nextItemId' => $next, 'approved' => $approved, 'rejected' => $rejected];
        });
    }

    // ---- remove / delete -----------------------------------------------------------------------------------------

    /** Removes one unfinished document with its files. False for a finished item, null outside the scope. */
    public function removeItem(Viewer $viewer, MedicalSubject $subject, string $batchId, string $itemId, User $user): ?bool
    {
        $paths = [];
        $subjectId = (string) $subject->getKey();

        $result = DB::transaction(function () use ($viewer, $subject, $subjectId, $batchId, $itemId, $user, &$paths): ?bool {
            if ($this->locked($viewer, $subject, $batchId, true) === null) {
                return null;
            }
            $item = $this->item($batchId, $itemId, true);
            if ($item === null) {
                return null;
            }
            if ($item->state === 'done') {
                return false;
            }
            $documentId = (string) $item->document_id;
            $this->jobs->cancelBatchRuns($batchId, $viewer->userId(), $documentId);
            $document = Visibility::apply(DB::table('medical_documents')->where('id', $documentId)->where('subject_id', $subjectId)->where('upload_batch_id', $batchId), $viewer, 'subject_id', AccessLevel::Edit)
                ->first(['path', 'thumb_path']);
            if ($document === null) {
                return null;
            }
            foreach ([$document->path, $document->thumb_path] as $path) {
                if ($path !== null && $path !== '') {
                    $paths[] = (string) $path;
                }
            }
            // The foreign keys cascade: the item and the document's AI rows.
            Visibility::apply(DB::table('medical_documents')->where('id', $documentId)->where('subject_id', $subjectId)->where('upload_batch_id', $batchId), $viewer, 'subject_id', AccessLevel::Edit)->delete();
            $now = Carbon::now();
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->where('current_item_id', $itemId)->update(['current_item_id' => null]);
            $this->scopedBatches($viewer, $subject)->where('id', $batchId)->update(['acted_by' => $viewer->userId(), 'updated_at' => $now]);
            $this->auditBatch($user, $subjectId, $batchId, 'removed', 1);

            return true;
        });

        if ($result === true && $paths !== []) {
            // After commit, so a rollback keeps the files; prune-orphans is only the backstop.
            foreach ($paths as $path) {
                try {
                    $this->store->delete([$path]);
                } catch (InvalidArgumentException) {
                }
            }
        }

        return $result;
    }

    /** Deletes the whole batch with its unfinished documents. The number of documents removed, null outside the scope. */
    public function delete(Viewer $viewer, MedicalSubject $subject, string $batchId, User $user): ?int
    {
        if ($this->locked($viewer, $subject, $batchId, false) === null) {
            return null;
        }

        return $this->jobs->purge($batchId, $user->id, 'deleted');
    }

    // ---- internals -----------------------------------------------------------------------------------------------

    /** The batch inside the Edit scope of the person; with $forUpdate under the per-batch advisory lock and a row lock (the tick's order). */
    private function locked(Viewer $viewer, MedicalSubject $subject, string $batchId, bool $forUpdate): ?object
    {
        if (! $viewer->canEdit() || ! Str::isUuid($batchId)) {
            return null;
        }
        if ($forUpdate) {
            DB::select("select pg_advisory_xact_lock(hashtext('upload-batch:'||?::text))", [$batchId]);
        }
        $query = $this->scopedBatches($viewer, $subject)->where('id', $batchId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first(['id', 'subject_id', 'status', 'current_item_id', 'expires_at', 'finished_at']);
    }

    private function scopedBatches(Viewer $viewer, MedicalSubject $subject): Builder
    {
        return Visibility::apply(DB::table('medical_upload_batches')->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);
    }

    /** The items of one batch, inside the same membership scope as the batch (the subselect carries the predicate). */
    private function scopedItems(Viewer $viewer, MedicalSubject $subject, string $batchId): Builder
    {
        return DB::table('medical_upload_batch_items')->where('batch_id', $batchId)
            ->whereIn('batch_id', $this->scopedBatches($viewer, $subject)->where('id', $batchId)->select('id'));
    }

    private function openBatchId(Viewer $viewer, string $subjectId): ?string
    {
        $id = Visibility::apply(DB::table('medical_upload_batches')->where('subject_id', $subjectId)->where('status', 'open'), $viewer, 'subject_id', AccessLevel::Edit)->value('id');

        return $id === null ? null : (string) $id;
    }

    /** An item looked up by (id, batch_id); the batch itself was resolved through the scope before. */
    private function item(string $batchId, string $itemId, bool $forUpdate): ?object
    {
        if (! Str::isUuid($itemId)) {
            return null;
        }
        $query = DB::table('medical_upload_batch_items')->where('id', $itemId)->where('batch_id', $batchId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** Stores the member's acknowledgment of the enabled provider set unless it is already stored. Call after acknowledgmentNeeded() answered null. */
    private function acknowledge(Viewer $viewer): void
    {
        $enabled = $this->runs->providers();
        if (! $this->runs->noticeAcknowledged($viewer, $enabled)) {
            $this->runs->recordAcknowledgment($viewer, $enabled);
        }
    }

    /** The ready proposal payload of the item's run inside the Edit scope, or null. @return array<string, mixed>|null */
    private function readyPayload(Viewer $viewer, MedicalSubject $subject, object $item): ?array
    {
        if ($item->extraction_id === null) {
            return null;
        }
        $row = Visibility::apply(
            DB::table('medical_ai_extractions')->where('id', (string) $item->extraction_id)->where('subject_id', $subject->getKey())->where('status', 'ready'),
            $viewer, 'subject_id', AccessLevel::Edit,
        )->first(['payload']);
        if ($row === null) {
            return null;
        }

        return $this->decode($row->payload);
    }

    /**
     * The items of one batch with their display state. The batch id is checked against the viewer's Edit scope in the
     * same statement.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Viewer $viewer, MedicalSubject $subject, string $batchId, bool $consentOn): array
    {
        $rows = DB::table('medical_upload_batch_items as i')
            ->join('medical_documents as d', 'd.id', '=', 'i.document_id')
            ->leftJoin('medical_ai_extractions as e', 'e.id', '=', 'i.extraction_id')
            ->whereIn('i.batch_id', $this->scopedBatches($viewer, $subject)->where('id', $batchId)->select('id'))
            ->orderBy('i.position')
            ->select(['i.id', 'i.position', 'i.document_id', 'i.state', 'i.rescan_count', 'i.duplicate_of', 'd.title', 'd.kind',
                'e.status as run_status', 'e.cancel_reason', 'e.available_at', 'e.failure_code', 'e.entry_count'])
            ->selectSub(
                DB::table('medical_ai_decisions')->selectRaw('count(*)')->whereColumn('medical_ai_decisions.extraction_id', 'e.id'),
                'decided',
            )
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $state = ItemState::for((string) $row->state, $row->run_status === null ? null : (string) $row->run_status, $row->cancel_reason === null ? null : (string) $row->cancel_reason, $consentOn);
            $item = [
                'id' => (string) $row->id,
                'position' => (int) $row->position,
                'documentId' => (string) $row->document_id,
                'title' => (string) $row->title,
                'kind' => (string) $row->kind,
                'state' => $state,
                'rescanCount' => (int) $row->rescan_count,
                'duplicateOf' => $row->duplicate_of === null ? null : (string) $row->duplicate_of,
            ];
            if ($state === 'paused' && $row->available_at !== null) {
                $item['availableAt'] = Carbon::parse($row->available_at)->toIso8601String();
            }
            if ($state === 'failed') {
                $item['failureCode'] = $row->cancel_reason === 'access' ? 'access' : (string) ($row->failure_code ?? 'unknown');
            }
            if ($state === 'review') {
                $item['pending'] = max(0, (int) $row->entry_count - (int) $row->decided);
            }
            $out[] = $item;
        }

        return $out;
    }

    /** The cursor: the stored item while it is unfinished, otherwise the first unfinished item, otherwise null. @param list<array<string, mixed>> $items */
    private function currentItemId(object $batch, array $items): ?string
    {
        $stored = $batch->current_item_id === null ? null : (string) $batch->current_item_id;
        foreach ($items as $item) {
            if ($item['id'] === $stored && $item['state'] !== 'done') {
                return $stored;
            }
        }
        foreach ($items as $item) {
            if ($item['state'] !== 'done') {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function batchProps(object $batch, array $items, ?string $currentId): array
    {
        $count = fn (array $states) => count(array_filter($items, fn ($i) => in_array($i['state'], $states, true)));

        return [
            'id' => (string) $batch->id,
            'status' => (string) $batch->status,
            'expiresAt' => Carbon::parse($batch->expires_at)->toIso8601String(),
            'currentItemId' => $currentId,
            'counts' => [
                'total' => count($items),
                'done' => $count(['done']),
                'review' => $count(['review']),
                // Waiting for the system: not sent yet, paused for quota, or consent is off.
                'processing' => $count(['waiting', 'consent_off', 'processing', 'paused']),
                'failed' => $count(['failed', 'stopped']),
            ],
        ];
    }

    /** The first unfinished item after $afterPosition, else the first unfinished one, else null. */
    private function nextUnfinished(string $batchId, ?int $afterPosition): ?string
    {
        $open = fn () => DB::table('medical_upload_batch_items')->where('batch_id', $batchId)->where('state', '!=', 'done')->orderBy('position');
        $id = $afterPosition === null ? null : $open()->where('position', '>', $afterPosition)->value('id');
        $id ??= $open()->value('id');

        return $id === null ? null : (string) $id;
    }

    private function auditBatch(User $user, string $subjectId, string $batchId, string $action, int $count): void
    {
        $this->audit->record(AuditEvent::MedicalUploadBatchChanged, $user, [
            'subject' => $subjectId, 'batch' => $batchId, 'action' => $action, 'count' => $count,
        ], null, Module::Health);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $raw): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private static function rulesFor(string $type): array
    {
        return match ($type) {
            'visit' => VisitRequest::fieldRules(),
            'medication' => MedicationRequest::fieldRules(),
            'allergy' => AllergyRequest::fieldRules(),
            default => VaccinationRequest::fieldRules(),
        };
    }
}
