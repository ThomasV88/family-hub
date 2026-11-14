<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Medications of one subject, with the VisitQuery semantics: every statement is constrained to the subject and to the
 * viewer's scope; source is always 'manual' and verified_at is never set here.
 */
class MedicationQuery
{
    private const FIELDS = ['name', 'dose', 'schedule', 'started_on', 'ended_on', 'notes'];

    /**
     * Ongoing first (by name), then ended by end date, newest first. $today is the family-timezone date computed by
     * the caller in PHP, never the database clock.
     *
     * @return Collection<int, MedicalMedication>
     */
    public function forSubject(Viewer $viewer, MedicalSubject $subject, string $today): Collection
    {
        return Visibility::apply(MedicalMedication::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderByRaw('case when ended_on is null or ended_on >= ? then 0 else 1 end', [$today])
            ->orderByRaw('case when ended_on is null or ended_on >= ? then lower(name) end', [$today])
            ->orderByRaw('case when ended_on < ? then ended_on end desc', [$today])
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();
    }

    /**
     * Medications taken today: no end date or an end date that is today or later.
     *
     * @return Collection<int, MedicalMedication>
     */
    public function current(Viewer $viewer, MedicalSubject $subject, string $today): Collection
    {
        return Visibility::apply(MedicalMedication::query()->where('subject_id', $subject->getKey()), $viewer)
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $today))
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();
    }

    public function find(Viewer $viewer, MedicalSubject $subject, string $medicationId, AccessLevel $min = AccessLevel::View): ?MedicalMedication
    {
        if (! Str::isUuid($medicationId)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(
            MedicalMedication::query()->where('id', $medicationId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            $min,
        )->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Viewer $viewer, MedicalSubject $subject, array $data): MedicalMedication
    {
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);

        $medication = new MedicalMedication;
        $medication->forceFill($this->attributes($data) + [
            'subject_id' => $subject->getKey(),
            'source' => 'manual',
            'verified_at' => null,
            'created_by' => $viewer->userId(),
        ])->save();

        return $medication;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Viewer $viewer, MedicalSubject $subject, string $medicationId, array $data): bool
    {
        if (! Str::isUuid($medicationId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $medicationId)
            ->update($this->attributes($data) + ['updated_at' => now()]) > 0;
    }

    public function delete(Viewer $viewer, MedicalSubject $subject, string $medicationId): bool
    {
        if (! Str::isUuid($medicationId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $medicationId)->delete() > 0;
    }

    private function scoped(Viewer $viewer, MedicalSubject $subject, string $medicationId): Builder
    {
        return Visibility::apply(
            DB::table('medical_medications')->where('id', $medicationId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            AccessLevel::Edit,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            // A field the client did not send (notes has no input yet) keeps its stored value on update.
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }
}
