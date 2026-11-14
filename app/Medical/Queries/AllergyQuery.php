<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalAllergy;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Allergies of one subject, with the VisitQuery semantics: every statement is constrained to the subject and to the
 * viewer's scope; source is always 'manual' and verified_at is never set here.
 */
class AllergyQuery
{
    private const FIELDS = ['substance', 'reaction', 'severity'];

    /** @return Collection<int, MedicalAllergy> severe first */
    public function forSubject(Viewer $viewer, MedicalSubject $subject): Collection
    {
        return Visibility::apply(MedicalAllergy::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderByRaw("case severity when 'severe' then 0 when 'moderate' then 1 when 'mild' then 2 else 3 end")
            ->orderByRaw('lower(substance)')
            ->orderBy('id')
            ->get();
    }

    public function find(Viewer $viewer, MedicalSubject $subject, string $allergyId, AccessLevel $min = AccessLevel::View): ?MedicalAllergy
    {
        if (! Str::isUuid($allergyId)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(
            MedicalAllergy::query()->where('id', $allergyId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            $min,
        )->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Viewer $viewer, MedicalSubject $subject, array $data): MedicalAllergy
    {
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);

        $allergy = new MedicalAllergy;
        $allergy->forceFill($this->attributes($data) + [
            'subject_id' => $subject->getKey(),
            'source' => 'manual',
            'verified_at' => null,
            'created_by' => $viewer->userId(),
        ])->save();

        return $allergy;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Viewer $viewer, MedicalSubject $subject, string $allergyId, array $data): bool
    {
        if (! Str::isUuid($allergyId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $allergyId)
            ->update($this->attributes($data) + ['updated_at' => now()]) > 0;
    }

    public function delete(Viewer $viewer, MedicalSubject $subject, string $allergyId): bool
    {
        if (! Str::isUuid($allergyId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $allergyId)->delete() > 0;
    }

    private function scoped(Viewer $viewer, MedicalSubject $subject, string $allergyId): Builder
    {
        return Visibility::apply(
            DB::table('medical_allergies')->where('id', $allergyId)->where('subject_id', $subject->getKey()),
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
            $out[$field] = $data[$field] ?? null;
        }

        return $out;
    }
}
