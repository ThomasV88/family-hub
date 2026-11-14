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
 * viewer's scope; source is 'manual' unless the caller passes 'ai' (never from client input) and verified_at is never set here.
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
    public function create(Viewer $viewer, MedicalSubject $subject, array $data, string $source = 'manual'): MedicalAllergy
    {
        self::assertSource($source);
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);

        $allergy = new MedicalAllergy;
        $allergy->forceFill($this->attributes($data) + [
            'subject_id' => $subject->getKey(),
            'source' => $source,
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

    /**
     * Marks an AI-sourced, still unverified record as verified by this viewer. One scoped UPDATE (Edit scope); false
     * when nothing changed (manual, already verified, hidden, view-only or unknown id).
     */
    public function verify(Viewer $viewer, MedicalSubject $subject, string $allergyId): bool
    {
        if (! Str::isUuid($allergyId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $allergyId)
            ->where('source', 'ai')
            ->whereNull('verified_at')
            ->update(['verified_at' => now(), 'verified_by' => $viewer->userId(), 'updated_at' => now()]) > 0;
    }

    private static function assertSource(string $source): void
    {
        if (! in_array($source, ['manual', 'ai'], true)) {
            throw new \InvalidArgumentException('Invalid record source.');
        }
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
