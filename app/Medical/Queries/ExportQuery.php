<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Provenance;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalAllergy;
use App\Models\MedicalDocument;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Reads for exports. Every statement carries the subject constraint and the Edit scope (exports need "can change"),
 * records are read lazily in chunks, and unverified rows are left out unless asked for.
 */
class ExportQuery
{
    private const CHUNK = 200;

    /** Edit scope: hidden, unknown, malformed and view-only all give null. */
    public function subject(Viewer $viewer, string $id): ?MedicalSubject
    {
        if (! Str::isUuid($id) || ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(MedicalSubject::query()->where('id', $id), $viewer, 'id', AccessLevel::Edit)->first();
    }

    /** @return LazyCollection<int, MedicalVisit> */
    public function visits(Viewer $viewer, MedicalSubject $subject, bool $includeUnverified, ?string $specialty = null): LazyCollection
    {
        $query = Visibility::apply(MedicalVisit::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);
        if ($specialty !== null) {
            $query->where('specialty_key', $specialty);
        }

        return $this->lazy($this->filtered($query, $includeUnverified)
            ->orderByDesc('visited_on')->orderByDesc('created_at')->orderBy('id'));
    }

    /** @return LazyCollection<int, MedicalMedication> */
    public function medications(Viewer $viewer, MedicalSubject $subject, bool $includeUnverified): LazyCollection
    {
        $query = Visibility::apply(MedicalMedication::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);

        return $this->lazy($this->filtered($query, $includeUnverified)
            ->orderByRaw('lower(name)')->orderBy('id'));
    }

    /** @return LazyCollection<int, MedicalAllergy> */
    public function allergies(Viewer $viewer, MedicalSubject $subject, bool $includeUnverified): LazyCollection
    {
        $query = Visibility::apply(MedicalAllergy::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);

        return $this->lazy($this->filtered($query, $includeUnverified)
            ->orderByRaw("case severity when 'severe' then 0 when 'moderate' then 1 when 'mild' then 2 else 3 end")
            ->orderByRaw('lower(substance)')->orderBy('id'));
    }

    /** @return LazyCollection<int, MedicalVaccination> */
    public function vaccinations(Viewer $viewer, MedicalSubject $subject, bool $includeUnverified): LazyCollection
    {
        $query = Visibility::apply(MedicalVaccination::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);

        return $this->lazy($this->filtered($query, $includeUnverified)
            ->orderByDesc('given_on')->orderBy('id'));
    }

    /**
     * Document metadata only (never file bytes). With a specialty, only documents attached to visits of that specialty.
     *
     * @return LazyCollection<int, MedicalDocument>
     */
    public function documents(Viewer $viewer, MedicalSubject $subject, bool $includeUnverified, ?string $specialty = null): LazyCollection
    {
        $query = Visibility::apply(MedicalDocument::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);
        if ($specialty !== null) {
            $visitIds = Visibility::apply(
                DB::table('medical_visits')->select('id')->where('subject_id', $subject->getKey())->where('specialty_key', $specialty),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            );
            $query->whereIn('visit_id', $visitIds);
        }

        return $this->lazy($this->filtered($query, $includeUnverified)
            ->orderByDesc('created_at')->orderBy('id'));
    }

    /** Whether any record of this person is unverified (the form shows the checkbox only then). */
    public function hasUnverified(Viewer $viewer, MedicalSubject $subject): bool
    {
        foreach ([MedicalVisit::class, MedicalMedication::class, MedicalAllergy::class, MedicalVaccination::class, MedicalDocument::class] as $model) {
            $query = Visibility::apply($model::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit);
            if (Provenance::onlyUnverified($query)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{value: string, label: string}> specialties this person has visits for, in taxonomy order */
    public function specialtiesWithVisits(Viewer $viewer, MedicalSubject $subject): array
    {
        $keys = Visibility::apply(MedicalVisit::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit)
            ->distinct()->pluck('specialty_key')->all();

        $out = [];
        foreach (Specialty::cases() as $case) {
            if (in_array($case->value, $keys, true)) {
                $out[] = ['value' => $case->value, 'label' => $case->label()];
            }
        }

        return $out;
    }

    private function filtered(Builder $query, bool $includeUnverified): Builder
    {
        return $includeUnverified ? $query : Provenance::excludeUnverified($query);
    }

    /** @return LazyCollection<int, mixed> */
    private function lazy(Builder $query): LazyCollection
    {
        return $query->lazy(self::CHUNK);
    }
}
