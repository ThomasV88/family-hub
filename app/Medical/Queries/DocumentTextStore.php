<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Documents\Text\SaveOutcome;
use App\Medical\Documents\Text\TextNormalizer;
use App\Medical\Viewer;
use App\Medical\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer and deleter of the recognised document text (a Queries class: raw database access and the table name
 * stay in this layer). The job side (save) is unscoped by design, like AiJobQuery; the request side (remove,
 * purgeForSubject) carries Visibility at Edit level in the same statement.
 *
 * Nothing here catches an exception or logs: a QueryException message carries the bound values, so the caller (the job's
 * content-free catch) is the one place that decides what leaves the process.
 */
class DocumentTextStore
{
    private const LANGUAGES = ['sk', 'pl', 'en', 'mixed', 'other'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Stores the text of a document, replacing an earlier one. Writes only while the person's AI consent is on and
     * neither the person nor the document is archived; the person row is locked FOR SHARE so a consent change (FOR
     * UPDATE) cannot interleave.
     *
     * @param  list<string>  $pages
     */
    public function save(string $documentId, ?string $extractionId, int $startedBy, ?string $agent, ?string $model, ?string $language, array $pages, int $pagesTotal, bool $complete): SaveOutcome
    {
        if (! Str::isUuid($documentId)) {
            return SaveOutcome::Missing;
        }
        $body = TextNormalizer::body($pages);
        // Pages that did not fit the total limit are dropped whole: record what the body really holds.
        $kept = $pages === [] ? 0 : substr_count($body, TextNormalizer::PAGE_SEPARATOR) + 1;
        $pagesRead = min($kept, count($pages));
        $complete = $complete && $pagesRead === $pagesTotal;

        return DB::transaction(function () use ($documentId, $extractionId, $startedBy, $agent, $model, $language, $body, $pagesRead, $pagesTotal, $complete) {
            $subjectId = $this->subjectOf($documentId);
            if ($subjectId === null) {
                return SaveOutcome::Missing;
            }
            $subject = DB::table('medical_subjects')->where('id', $subjectId)->sharedLock()->first(['ai_consent', 'archived_at']);
            if ($subject === null) {
                return SaveOutcome::Missing;
            }
            if ($subject->archived_at !== null) {
                return SaveOutcome::Archived;
            }
            if (! $subject->ai_consent) {
                return SaveOutcome::ConsentOff;
            }
            $document = DB::table('medical_documents')->where('id', $documentId)->sharedLock()->first(['archived_at']);
            if ($document === null) {
                return SaveOutcome::Missing;
            }
            if ($document->archived_at !== null) {
                return SaveOutcome::Archived;
            }

            $this->write([
                'document_id' => $documentId,
                'subject_id' => $subjectId,
                'extraction_id' => $extractionId,
                'started_by' => $startedBy,
                'agent' => $agent,
                'model' => $model,
                'text_version' => (int) config('ai.ocr.text_version', 1),
                'language' => in_array($language, self::LANGUAGES, true) ? $language : null,
                'pages_read' => $pagesRead,
                'pages_total' => $pagesTotal,
                'complete' => $complete,
                'body' => $body,
            ]);

            return SaveOutcome::Saved;
        });
    }

    /** Removes the stored text of one document. Edit viewers only; an archived document keeps its text hidden, untouched. */
    public function remove(Viewer $viewer, string $subjectId, string $documentId): bool
    {
        if (! $viewer->canEdit() || ! Str::isUuid($subjectId) || ! Str::isUuid($documentId)) {
            return false;
        }

        return DB::table('medical_document_texts')
            ->where('document_id', $documentId)
            ->where('subject_id', $subjectId)
            ->whereIn('subject_id', Visibility::subjectIds($viewer, AccessLevel::Edit))
            ->whereIn('document_id', fn ($q) => $q->select('id')->from('medical_documents')->where('subject_id', $subjectId)->whereNull('archived_at'))
            ->delete() > 0;
    }

    /**
     * The request-side removal: remove() and the content-free audit row (ids only, never a title or any text) in one
     * transaction, so a removal never goes unrecorded and a failed audit write removes nothing.
     */
    public function removeAudited(Viewer $viewer, string $subjectId, string $documentId): bool
    {
        return DB::transaction(function () use ($viewer, $subjectId, $documentId): bool {
            if (! $this->remove($viewer, $subjectId, $documentId)) {
                return false;
            }
            $this->audit->record(AuditEvent::MedicalTextRemoved, $viewer->user, ['subject' => $subjectId, 'record' => $documentId], null, Module::Health);

            return true;
        });
    }

    /** Deletes the text of every document of one person (consent withdrawn). Returns the number of rows removed. */
    public function purgeForSubject(Viewer $viewer, string $subjectId): int
    {
        if (! $viewer->canEdit() || ! Str::isUuid($subjectId)) {
            return 0;
        }

        return DB::table('medical_document_texts')
            ->where('subject_id', $subjectId)
            ->whereIn('subject_id', Visibility::subjectIds($viewer, AccessLevel::Edit))
            ->delete();
    }

    private function subjectOf(string $documentId): ?string
    {
        $id = DB::table('medical_documents')->where('id', $documentId)->value('subject_id');

        return $id === null ? null : (string) $id;
    }

    /** @param  array<string, mixed>  $row */
    private function write(array $row): void
    {
        $now = Carbon::now();
        DB::table('medical_document_texts')->upsert(
            [$row + ['created_at' => $now, 'updated_at' => $now]],
            ['document_id'],
            ['subject_id', 'extraction_id', 'started_by', 'agent', 'model', 'text_version', 'language', 'pages_read', 'pages_total', 'complete', 'body', 'updated_at'],
        );
    }
}
