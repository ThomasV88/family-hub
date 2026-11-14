<?php

namespace App\Medical\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unscoped by design: runs without a viewer (Phase 8 worker, scheduler, outbox). Returns ids, dates or booleans only,
 * never record content, plus `subjectDisplayName` used only for the owner-accepted reminder title. Every method has
 * its own test.
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

    /**
     * Vaccinations whose next due date lies in [fromDate, toDate] (inclusive, Y-m-d), soonest first.
     *
     * @return list<array{id: int, public_id: string, subject_id: string, next_due_on: string}>
     */
    public function dueVaccinations(string $fromDate, string $toDate): array
    {
        return DB::table('medical_vaccinations')
            ->select(['id', 'public_id', 'subject_id', 'next_due_on'])
            ->whereNotNull('next_due_on')
            ->whereBetween('next_due_on', [$fromDate, $toDate])
            ->orderBy('next_due_on')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'public_id' => (string) $r->public_id, 'subject_id' => (string) $r->subject_id,
                'next_due_on' => substr((string) $r->next_due_on, 0, 10),
            ])
            ->all();
    }

    /** @return array{public_id: string, subject_id: string, next_due_on: string}|null null when gone or without a due date */
    public function vaccinationDue(int $id): ?array
    {
        $row = DB::table('medical_vaccinations')->select(['public_id', 'subject_id', 'next_due_on'])
            ->where('id', $id)->whereNotNull('next_due_on')->first();

        return $row === null ? null : [
            'public_id' => (string) $row->public_id, 'subject_id' => (string) $row->subject_id,
            'next_due_on' => substr((string) $row->next_due_on, 0, 10),
        ];
    }

    /**
     * Users who might edit this person: members with level 2 plus the linked user while self access is on. The caller
     * still applies the module level.
     *
     * @return list<int>
     */
    public function reminderCandidateIds(string $subjectId): array
    {
        if (! Str::isUuid($subjectId)) {
            return [];
        }
        $own = DB::table('medical_subjects')->select('user_id')
            ->where('id', $subjectId)->where('self_access_enabled', true)->whereNotNull('user_id');

        return DB::table('medical_subject_members')->select('user_id')
            ->where('subject_id', $subjectId)->where('level', '>=', 2)
            ->union($own)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** The same predicate as reminderCandidateIds for one user. */
    public function isEditor(string $subjectId, int $userId): bool
    {
        return in_array($userId, $this->reminderCandidateIds($subjectId), true);
    }

    /** Only for the owner-accepted reminder title (person name); never log it. */
    public function subjectDisplayName(string $subjectId): ?string
    {
        if (! Str::isUuid($subjectId)) {
            return null;
        }
        $name = DB::table('medical_subjects')->where('id', $subjectId)->value('name');

        return $name === null ? null : (string) $name;
    }
}
