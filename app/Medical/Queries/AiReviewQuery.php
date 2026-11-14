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
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The review side of an AI proposal: the page props and the per-entry decisions. Every statement on the proposal table
 * carries the viewer's Edit membership scope. Accepting an entry runs the Phase 7 record query with source 'ai' after the
 * same field rules as a manual save, in one transaction that holds the extraction row lock.
 */
class AiReviewQuery
{
    private const RACE = 'decision race lost';

    private const SECTIONS = ['visits' => 'visit', 'medications' => 'medication', 'allergies' => 'allergy', 'vaccinations' => 'vaccination'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentQuery $documents,
        private readonly VisitQuery $visits,
        private readonly MedicationQuery $medications,
        private readonly AllergyQuery $allergies,
        private readonly VaccinationQuery $vaccinations,
        private readonly MedicalDocumentStore $store,
        private readonly PdfRasterizer $rasterizer,
    ) {}

    /**
     * Null for a hidden, unknown or malformed document or extraction (the controller answers 403). `['redirect' => true]`
     * when the run is not reviewable (queued, running, failed, cancelled, or none), otherwise the page props.
     *
     * @return array<string, mixed>|null
     */
    public function review(Viewer $viewer, MedicalSubject $subject, string $documentId, ?string $extractionId): ?array
    {
        if (! $viewer->canEdit() || ! Str::isUuid($documentId) || ($extractionId !== null && ! Str::isUuid($extractionId))) {
            return null;
        }
        $subjectId = (string) $subject->getKey();
        $document = $this->documents->find($viewer, $subject, $documentId, AccessLevel::Edit);
        if ($document === null) {
            return null;
        }

        $scoped = fn () => Visibility::apply(
            DB::table('medical_ai_extractions')->where('subject_id', $subjectId)->where('document_id', $documentId),
            $viewer,
            'subject_id',
            AccessLevel::Edit,
        );
        $columns = ['id', 'status', 'payload', 'queued_at', 'finished_at', 'pages_read', 'pages_total', 'agent', 'model', 'available_at'];
        if ($extractionId !== null) {
            $row = $scoped()->where('id', $extractionId)->first($columns);
            if ($row === null) {
                return null;
            }
        } else {
            $row = $scoped()->where('status', '!=', 'superseded')->orderByDesc('queued_at')->orderByDesc('id')->first($columns);
            if ($row !== null && $row->status === 'waiting') {
                // A newer waiting run does not hide an older proposal that can still be reviewed.
                $row = $scoped()->where('status', 'ready')->orderByDesc('queued_at')->orderByDesc('id')->first($columns) ?? $row;
            }
        }
        if ($row === null || ! in_array($row->status, ['ready', 'superseded', 'waiting'], true)) {
            return ['redirect' => true];
        }
        if ($row->status === 'waiting') {
            return $this->waitingProps($viewer, $subjectId, $document, $row);
        }

        $payload = $this->payload($row->payload);
        $decisions = $this->decisions($viewer, (string) $row->id)->get()->keyBy('entry_key');

        $sections = [];
        $pending = 0;
        foreach (self::SECTIONS as $list => $type) {
            $items = [];
            foreach (array_keys(is_array($payload[$list] ?? null) ? $payload[$list] : []) as $i) {
                $key = $type.'-'.$i;
                $decision = $decisions->get($key);
                $state = $decision === null ? 'pending' : (string) $decision->decision;
                $pending += $decision === null ? 1 : 0;
                $items[] = [
                    'key' => $key,
                    'fields' => $payload[$list][$i],
                    'state' => $state,
                    'recordId' => $decision?->record_id === null ? null : (string) $decision->record_id,
                    'edited' => $decision !== null && (bool) $decision->edited,
                ];
            }
            $sections[$list] = $items;
        }

        $readOnly = $row->status === 'superseded';
        $newest = null;
        if ($readOnly) {
            $newestId = $scoped()->where('status', 'ready')->orderByDesc('queued_at')->orderByDesc('id')->value('id');
            $newest = $newestId === null ? null : (string) $newestId;
        }

        $newerWaiting = $scoped()->where('status', 'waiting')->where('queued_at', '>', $row->queued_at)
            ->orderByDesc('queued_at')->orderByDesc('id')->first(['id', 'available_at']);

        return [
            'person' => ['id' => $subjectId],
            'document' => [
                'id' => (string) $document->id,
                'kind' => (string) $document->kind,
                'title' => (string) $document->title,
                'pageCount' => $this->pageCount($document),
            ],
            'provenance' => $this->provenance($row),
            'newerWaiting' => $newerWaiting === null ? null : [
                'id' => (string) $newerWaiting->id,
                'availableAt' => Carbon::parse($newerWaiting->available_at)->toIso8601String(),
            ],
            'extraction' => [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'runAt' => Carbon::parse((string) ($row->finished_at ?? $row->queued_at))->toIso8601String(),
                'pagesRead' => $row->pages_read === null ? null : (int) $row->pages_read,
                'pagesTotal' => $row->pages_total === null ? null : (int) $row->pages_total,
                'documentDate' => $payload['document_date'] ?? null,
                'specialtyKey' => $payload['specialty_key'] ?? null,
                'readOnly' => $readOnly,
                'pending' => $pending,
                'sections' => $sections,
            ],
            'newestExtractionId' => $newest,
            'consentOn' => $this->consentOn($viewer, $subjectId),
            'specialties' => array_map(fn (Specialty $s) => $s->value, Specialty::cases()),
        ];
    }

    /** @return array{agent: string, model: string|null} */
    private function provenance(object $row): array
    {
        return [
            'agent' => (string) $row->agent,
            'model' => ($row->model === null || $row->model === 'default') ? null : (string) $row->model,
        ];
    }

    /**
     * The status block for a run that is waiting for AI quota: no entries, only the resume time.
     *
     * @return array<string, mixed>
     */
    private function waitingProps(Viewer $viewer, string $subjectId, object $document, object $row): array
    {
        return [
            'person' => ['id' => $subjectId],
            'document' => [
                'id' => (string) $document->id,
                'kind' => (string) $document->kind,
                'title' => (string) $document->title,
                'pageCount' => $this->pageCount($document),
            ],
            'provenance' => null,
            'newerWaiting' => null,
            'extraction' => [
                'id' => (string) $row->id,
                'status' => 'waiting',
                'availableAt' => Carbon::parse($row->available_at)->toIso8601String(),
                'runAt' => Carbon::parse((string) $row->queued_at)->toIso8601String(),
                'pagesRead' => null,
                'pagesTotal' => null,
                'documentDate' => null,
                'specialtyKey' => null,
                'readOnly' => true,
                'pending' => 0,
                'sections' => array_fill_keys(array_keys(self::SECTIONS), []),
            ],
            'newestExtractionId' => null,
            'consentOn' => $this->consentOn($viewer, $subjectId),
            'specialties' => array_map(fn (Specialty $s) => $s->value, Specialty::cases()),
        ];
    }

    /**
     * Creates the record from one proposal entry (optionally edited through $values). Null when the extraction or entry
     * is outside the viewer's scope (403). A validation failure throws ValidationException and leaves nothing behind.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function accept(Viewer $viewer, MedicalSubject $subject, string $extractionId, string $entryKey, ?array $values, User $user): ?array
    {
        try {
            return $this->decide($viewer, $subject, $extractionId, $entryKey, $user, function (object $row, array $entry, string $subjectId) use ($viewer, $subject, $entryKey, $values, $user): array {
                $type = $entry['type'];
                $rules = self::rulesFor($type);
                $allowed = array_keys($rules);
                $data = [];
                foreach ($allowed as $field) {
                    $data[$field] = self::clean($entry['fields'][$field] ?? null);
                }
                $edited = false;
                if ($values !== null) {
                    foreach ($allowed as $field) {
                        if (array_key_exists($field, $values)) {
                            $new = self::clean($values[$field]);
                            $edited = $edited || $new !== $data[$field];
                            $data[$field] = $new;
                        }
                    }
                }

                $validator = Validator::make($data, $rules, self::messagesFor($type));
                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }
                $valid = $validator->validated();

                // One lock per person for the whole accept: de-duplication and the caps below are checked under it, so two
                // concurrent accepts of the same content cannot both insert.
                DB::table('medical_subjects')->where('id', $subjectId)->lockForUpdate()->value('id');

                // A re-run proposes the same content again. An entry that matches a record the person already has is
                // linked to it, never inserted twice (and a duplicate vaccination never schedules a second reminder).
                $recordId = $this->existingRecordId($type, $subjectId, $valid);
                if ($recordId === null) {
                    $this->assertWithinLimits($subjectId, (string) $row->id);
                    $recordId = match ($type) {
                        'visit' => (string) $this->visits->create($viewer, $subject, $valid, 'ai')->getKey(),
                        'medication' => (string) $this->medications->create($viewer, $subject, $valid, 'ai')->getKey(),
                        'allergy' => (string) $this->allergies->create($viewer, $subject, $valid, 'ai')->getKey(),
                        default => (string) $this->vaccinations->create($viewer, $subject, $valid, 'ai')->public_id,
                    };
                }

                if ($type === 'visit') {
                    $this->linkDocument($viewer, $subject, (string) $row->document_id, $recordId);
                }

                DB::table('medical_ai_decisions')->insert([
                    'extraction_id' => $row->id,
                    'entry_key' => $entryKey,
                    'decision' => 'accepted',
                    'record_type' => $type,
                    'record_id' => $recordId,
                    'edited' => $edited,
                    'decided_by' => $viewer->userId(),
                    'decided_at' => Carbon::now(),
                ]);
                $this->auditDecision($user, $subjectId, (string) $row->document_id, 'accepted');

                return $this->state($viewer, (string) $row->id, $entryKey, $row->payload);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::RACE) {
                throw $e;
            }

            return $this->currentState($viewer, $subject, $extractionId, $entryKey);
        }
    }

    /** @return array<string, mixed>|null */
    public function reject(Viewer $viewer, MedicalSubject $subject, string $extractionId, string $entryKey, User $user): ?array
    {
        try {
            return $this->decide($viewer, $subject, $extractionId, $entryKey, $user, function (object $row, array $entry, string $subjectId) use ($viewer, $entryKey, $user): array {
                DB::table('medical_ai_decisions')->insert([
                    'extraction_id' => $row->id,
                    'entry_key' => $entryKey,
                    'decision' => 'rejected',
                    'record_type' => null,
                    'record_id' => null,
                    'edited' => false,
                    'decided_by' => $viewer->userId(),
                    'decided_at' => Carbon::now(),
                ]);
                $this->auditDecision($user, $subjectId, (string) $row->document_id, 'rejected');

                return $this->state($viewer, (string) $row->id, $entryKey, $row->payload);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::RACE) {
                throw $e;
            }

            return $this->currentState($viewer, $subject, $extractionId, $entryKey);
        }
    }

    /** Deletes only a rejected decision; an accepted entry stays accepted. @return array<string, mixed>|null */
    public function undo(Viewer $viewer, MedicalSubject $subject, string $extractionId, string $entryKey, User $user): ?array
    {
        return $this->decide($viewer, $subject, $extractionId, $entryKey, $user, function (object $row, array $entry, string $subjectId) use ($viewer, $entryKey, $user): array {
            $deleted = $this->decisions($viewer, (string) $row->id)->where('entry_key', $entryKey)->where('decision', 'rejected')->delete();
            if ($deleted > 0) {
                $this->auditDecision($user, $subjectId, (string) $row->document_id, 'undone');
            }

            return $this->state($viewer, (string) $row->id, $entryKey, $row->payload);
        }, existingShortCircuit: false);
    }

    /**
     * The common frame: one transaction, the extraction row locked FOR UPDATE through the Edit scope, status and entry
     * checks, and the existing-decision short circuit that makes a repeat a no-op.
     *
     * @param  callable(object, array{type: string, fields: array<string, mixed>}, string): array<string, mixed>  $apply
     * @return array<string, mixed>|null
     */
    private function decide(Viewer $viewer, MedicalSubject $subject, string $extractionId, string $entryKey, User $user, callable $apply, bool $existingShortCircuit = true): ?array
    {
        if (! $viewer->canEdit() || ! Str::isUuid($extractionId) || ! ProposalEntries::isKey($entryKey)) {
            return null;
        }
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $subjectId, $extractionId, $entryKey, $apply, $existingShortCircuit): ?array {
            $row = Visibility::apply(
                DB::table('medical_ai_extractions')->where('id', $extractionId)->where('subject_id', $subjectId),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            )->lockForUpdate()->first(['id', 'status', 'document_id', 'payload']);
            if ($row === null) {
                return null;
            }
            if ($row->status === 'superseded') {
                return ['state' => 'superseded'];
            }
            if ($row->status !== 'ready') {
                return null;
            }
            $entry = ProposalEntries::entry($this->payload($row->payload), $entryKey);
            if ($entry === null) {
                return null;
            }
            if ($existingShortCircuit && $this->decisions($viewer, (string) $row->id)->where('entry_key', $entryKey)->exists()) {
                return $this->state($viewer, (string) $row->id, $entryKey, $row->payload);
            }

            try {
                // A savepoint, so a lost insert race does not poison the outer transaction before it is rolled back.
                return DB::transaction(fn () => $apply($row, $entry, $subjectId));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    throw new RuntimeException(self::RACE);
                }
                throw $e;
            }
        });
    }

    /** The state of an entry after a lost race (the transaction, record included, was rolled back). @return array<string, mixed>|null */
    private function currentState(Viewer $viewer, MedicalSubject $subject, string $extractionId, string $entryKey): ?array
    {
        $row = Visibility::apply(
            DB::table('medical_ai_extractions')->where('id', $extractionId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            AccessLevel::Edit,
        )->first(['id', 'payload']);

        return $row === null ? null : $this->state($viewer, (string) $row->id, $entryKey, $row->payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Viewer $viewer, string $extractionId, string $entryKey, mixed $payload): array
    {
        $decision = $this->decisions($viewer, $extractionId)->where('entry_key', $entryKey)->first();
        $decided = $this->decisions($viewer, $extractionId)->count();
        $pending = max(0, count(ProposalEntries::keys($this->payload($payload))) - $decided);

        if ($decision === null) {
            return ['state' => 'pending', 'pending' => $pending];
        }
        if ($decision->decision === 'accepted') {
            return [
                'state' => 'accepted',
                'recordType' => (string) $decision->record_type,
                'recordId' => (string) $decision->record_id,
                'edited' => (bool) $decision->edited,
                'pending' => $pending,
            ];
        }

        return ['state' => 'rejected', 'pending' => $pending];
    }

    /** Decisions of one extraction, always inside the viewer's Edit scope (the table has no subject_id of its own). */
    private function decisions(Viewer $viewer, string $extractionId): Builder
    {
        return DB::table('medical_ai_decisions')
            ->where('extraction_id', $extractionId)
            ->whereIn('extraction_id', Visibility::apply(DB::table('medical_ai_extractions')->select('id'), $viewer, 'subject_id', AccessLevel::Edit));
    }

    /** Consent flag for display, read inside the same scope as the rest of the page. */
    private function consentOn(Viewer $viewer, string $subjectId): bool
    {
        return (bool) Visibility::apply(DB::table('medical_subjects')->where('id', $subjectId), $viewer, 'id', AccessLevel::Edit)->value('ai_consent');
    }

    private function auditDecision(User $user, string $subjectId, string $documentId, string $decision): void
    {
        $this->audit->record(AuditEvent::MedicalAiEntryDecided, $user, [
            'subject' => $subjectId, 'document' => $documentId, 'decision' => $decision,
        ], null, Module::Health);
    }

    /**
     * The id of a record of this person that already holds the same content (case-insensitive text, null-safe dates),
     * or null. Vaccinations answer with their public uuid like create does.
     *
     * @param  array<string, mixed>  $valid
     */
    private function existingRecordId(string $type, string $subjectId, array $valid): ?string
    {
        [$table, $idColumn, $columns] = match ($type) {
            'visit' => ['medical_visits', 'id', ['visited_on' => 'date', 'doctor' => 'text', 'specialty_key' => 'text', 'diagnosis' => 'text']],
            'medication' => ['medical_medications', 'id', ['name' => 'text', 'dose' => 'text', 'started_on' => 'date']],
            'allergy' => ['medical_allergies', 'id', ['substance' => 'text', 'reaction' => 'text']],
            default => ['medical_vaccinations', 'public_id', ['vaccine' => 'text', 'given_on' => 'date', 'next_due_on' => 'date']],
        };
        $query = DB::table($table)->where('subject_id', $subjectId);
        foreach ($columns as $column => $kind) {
            $value = $valid[$column] ?? null;
            if ($kind === 'date') {
                $query->whereRaw("{$column} is not distinct from ?::date", [$value === null ? null : (string) $value]);
            } else {
                $query->whereRaw("lower(btrim({$column})) is not distinct from lower(btrim(?::text))", [$value === null ? null : (string) $value]);
            }
        }
        $id = $query->orderBy('created_at')->value($idColumn);

        return $id === null ? null : (string) $id;
    }

    /** Caps the records one proposal and one person can gain through AI accepts. Called under the subject lock. */
    private function assertWithinLimits(string $subjectId, string $extractionId): void
    {
        $created = DB::table('medical_ai_decisions')->where('extraction_id', $extractionId)->where('decision', 'accepted')->count();
        $total = DB::table('medical_visits')->where('subject_id', $subjectId)->count()
            + DB::table('medical_medications')->where('subject_id', $subjectId)->count()
            + DB::table('medical_allergies')->where('subject_id', $subjectId)->count()
            + DB::table('medical_vaccinations')->where('subject_id', $subjectId)->count();
        if ($created >= (int) config('ai.max_accepts_per_proposal') || $total >= (int) config('medical.max_records_per_subject')) {
            throw ValidationException::withMessages(['record_limit' => ['limit']]);
        }
    }

    /** Attaches the document to the new visit, only when it has no visit yet. */
    private function linkDocument(Viewer $viewer, MedicalSubject $subject, string $documentId, string $visitId): void
    {
        $document = $this->documents->find($viewer, $subject, $documentId, AccessLevel::Edit);
        if ($document === null || $document->visit_id !== null) {
            return;
        }
        $this->documents->update($viewer, $subject, $documentId, (string) $document->title, true, $visitId);
    }

    private function pageCount(object $document): ?int
    {
        if ($document->kind !== 'pdf') {
            return null;
        }
        try {
            $absolute = $this->store->absolutePath((string) $document->path);
        } catch (InvalidArgumentException) {
            return 0;
        }
        if (! is_file($absolute)) {
            return 0;
        }

        return min($this->rasterizer->pageCount($absolute), (int) config('ai.pdf.view_max_pages'));
    }

    /** @return array<string, mixed> */
    private function payload(mixed $raw): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** An empty string is a cleared field. */
    private static function clean(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private static function rulesFor(string $type): array
    {
        return match ($type) {
            'visit' => VisitRequest::fieldRules(),
            'medication' => MedicationRequest::fieldRules(),
            'allergy' => AllergyRequest::fieldRules(),
            'vaccination' => VaccinationRequest::fieldRules(),
            default => throw new RuntimeException('Unknown entry type.'),
        };
    }

    /** @return array<string, string> */
    private static function messagesFor(string $type): array
    {
        return match ($type) {
            'visit' => VisitRequest::fieldMessages(),
            'medication' => MedicationRequest::fieldMessages(),
            'allergy' => AllergyRequest::fieldMessages(),
            'vaccination' => VaccinationRequest::fieldMessages(),
            default => [],
        };
    }
}
