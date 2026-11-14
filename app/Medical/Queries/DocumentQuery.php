<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Documents of one subject. Every statement is
 * constrained to the subject and the viewer's scope. A visit that is hidden, unknown or another person's gives one
 * identical validation error.
 */
class DocumentQuery
{
    public function __construct(private readonly VisitQuery $visits) {}

    public function countForSubject(Viewer $viewer, MedicalSubject $subject): int
    {
        return Visibility::apply(MedicalDocument::query()->where('subject_id', $subject->getKey()), $viewer)->count();
    }

    /** @return Collection<int, MedicalDocument> */
    public function forSubject(Viewer $viewer, MedicalSubject $subject): Collection
    {
        return Visibility::apply(MedicalDocument::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
    }

    /** Resolves the optional visit to a visit id of this subject, or fails with the one visit_id validation error. */
    public function resolveVisit(Viewer $viewer, MedicalSubject $subject, ?string $visitId): ?string
    {
        if ($visitId === null || $visitId === '') {
            return null;
        }
        $visit = $this->visits->find($viewer, $subject, $visitId);
        if ($visit === null) {
            throw ValidationException::withMessages(['visit_id' => 'health.upload.err_visit']);
        }

        return (string) $visit->getKey();
    }

    /** @param  array<string, mixed>  $stored  the document store put() result */
    public function create(Viewer $viewer, MedicalSubject $subject, array $stored, string $title, ?string $visitId): MedicalDocument
    {
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);
        $visitId = $this->resolveVisit($viewer, $subject, $visitId);

        $document = new MedicalDocument;
        $document->forceFill([
            'subject_id' => $subject->getKey(),
            'visit_id' => $visitId,
            'kind' => $stored['kind'],
            'title' => $title,
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'path' => $stored['path'],
            'thumb_path' => $stored['thumb_path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'source' => 'manual',
            'verified_at' => null,
            'uploaded_by' => $viewer->userId(),
        ])->save();

        return $document;
    }

    /** Null for a non-uuid id (no SQL), an id of another subject, a hidden subject and an unknown id alike. */
    public function find(Viewer $viewer, MedicalSubject $subject, string $documentId, AccessLevel $min = AccessLevel::View): ?MedicalDocument
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(
            MedicalDocument::query()->where('id', $documentId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            $min,
        )->first();
    }

    /**
     * Renames and, when $changeVisit is true, attaches (or with null detaches) the document. A visit that is hidden,
     * unknown or another person's gives the one visit_id validation error. False when no row matched.
     */
    public function update(Viewer $viewer, MedicalSubject $subject, string $documentId, string $title, bool $changeVisit, ?string $visitId): bool
    {
        if (! Str::isUuid($documentId) || ! $viewer->canEdit()) {
            return false;
        }
        $changes = ['title' => $title, 'updated_at' => now()];
        if ($changeVisit) {
            $changes['visit_id'] = $this->resolveVisit($viewer, $subject, $visitId);
        }

        return Visibility::apply(
            DB::table('medical_documents')->where('id', $documentId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            AccessLevel::Edit,
        )->update($changes) > 0;
    }

    /**
     * Deletes the row in one scoped statement and returns the storage paths to remove after the commit, or null when
     * no row matched (hidden, unknown, malformed or view-only).
     *
     * @return list<string>|null
     */
    public function delete(Viewer $viewer, MedicalSubject $subject, string $documentId): ?array
    {
        if (! Str::isUuid($documentId) || ! $viewer->canEdit()) {
            return null;
        }

        return DB::transaction(function () use ($viewer, $subject, $documentId): ?array {
            $scoped = fn () => Visibility::apply(
                DB::table('medical_documents')->where('id', $documentId)->where('subject_id', $subject->getKey()),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            );
            $row = $scoped()->first(['path', 'thumb_path']);
            if ($row === null || $scoped()->delete() === 0) {
                return null;
            }

            return array_values(array_filter([$row->path, $row->thumb_path]));
        });
    }
}
