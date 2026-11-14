<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Visits of one subject. Every statement is constrained to the subject and to the viewer's scope, so a hidden subject,
 * a visit of another subject and an unknown id all match zero rows. source is always 'manual' and verified_at is never
 * set here (the database refuses that combination too).
 */
class VisitQuery
{
    private const FIELDS = ['visited_on', 'doctor', 'specialty_key', 'specialty_other', 'diagnosis', 'notes'];

    /** @return Collection<int, MedicalVisit> */
    public function forSubject(Viewer $viewer, MedicalSubject $subject): Collection
    {
        return Visibility::apply(MedicalVisit::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderByDesc('visited_on')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
    }

    public function find(Viewer $viewer, MedicalSubject $subject, string $visitId, AccessLevel $min = AccessLevel::View): ?MedicalVisit
    {
        if (! Str::isUuid($visitId)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(
            MedicalVisit::query()->where('id', $visitId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            $min,
        )->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Viewer $viewer, MedicalSubject $subject, array $data): MedicalVisit
    {
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);

        $visit = new MedicalVisit;
        $visit->forceFill($this->attributes($data) + [
            'subject_id' => $subject->getKey(),
            'source' => 'manual',
            'verified_at' => null,
            'created_by' => $viewer->userId(),
        ])->save();

        return $visit;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Viewer $viewer, MedicalSubject $subject, string $visitId, array $data): bool
    {
        if (! Str::isUuid($visitId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $visitId)
            ->update($this->attributes($data, true) + ['updated_at' => now()]) > 0;
    }

    public function delete(Viewer $viewer, MedicalSubject $subject, string $visitId): bool
    {
        if (! Str::isUuid($visitId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $visitId)->delete() > 0;
    }

    private function scoped(Viewer $viewer, MedicalSubject $subject, string $visitId): Builder
    {
        return Visibility::apply(
            DB::table('medical_visits')->where('id', $visitId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            AccessLevel::Edit,
        );
    }

    /**
     * With $partial, a field the client did not send is left out of the result (so the UPDATE keeps the stored value);
     * a field sent as null still clears it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $partial = false): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if ($partial && ! array_key_exists($field, $data)) {
                continue;
            }
            $out[$field] = $data[$field] ?? null;
        }
        if (array_key_exists('specialty_key', $out) && $out['specialty_key'] !== 'other') {
            $out['specialty_other'] = null;
        }

        return $out;
    }
}
