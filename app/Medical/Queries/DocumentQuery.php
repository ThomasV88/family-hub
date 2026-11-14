<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Documents of one subject (create side; find, update and delete follow with the management plan). Every statement is
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
}
