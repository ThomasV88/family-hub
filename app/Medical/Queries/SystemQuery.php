<?php

namespace App\Medical\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unscoped by design: runs without a viewer (Phase 8 worker, scheduler, outbox). Returns ids, dates or booleans only,
 * never names or record content. Every method has its own test.
 */
class SystemQuery
{
    /** True only when the subject exists and its consent flag is on; false for a non-uuid (no SQL) or a missing row. */
    public function aiConsentFor(string $subjectId): bool
    {
        if (! Str::isUuid($subjectId)) {
            return false;
        }

        return (bool) DB::table('medical_subjects')->where('id', $subjectId)->value('ai_consent');
    }

    /** The subject id of a document, or null for a non-uuid (no SQL) or a missing row. */
    public function documentSubjectId(string $documentId): ?string
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }
        $id = DB::table('medical_documents')->where('id', $documentId)->value('subject_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Which of the given storage paths a document row references (full or thumbnail).
     *
     * @param  list<string>  $relativePaths
     * @return list<string>
     */
    public function knownPaths(array $relativePaths): array
    {
        $known = [];
        foreach (array_chunk(array_values(array_unique($relativePaths)), 500) as $chunk) {
            $rows = DB::table('medical_documents')->select(['path', 'thumb_path'])
                ->where(fn ($q) => $q->whereIn('path', $chunk)->orWhereIn('thumb_path', $chunk))
                ->get();
            foreach ($rows as $row) {
                foreach ([$row->path, $row->thumb_path] as $path) {
                    if ($path !== null && in_array($path, $chunk, true)) {
                        $known[$path] = true;
                    }
                }
            }
        }

        return array_keys($known);
    }

    /**
     * Which of the given ids are subjects that still exist (non-uuid entries are never matched).
     *
     * @param  list<string>  $subjectIds
     * @return list<string>
     */
    public function existingSubjectIds(array $subjectIds): array
    {
        $valid = array_values(array_unique(array_filter($subjectIds, fn ($id) => is_string($id) && Str::isUuid($id))));
        if ($valid === []) {
            return [];
        }

        return array_map('strval', DB::table('medical_subjects')->whereIn('id', $valid)->pluck('id')->all());
    }
}
