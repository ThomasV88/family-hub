<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vaccinations of one subject, with the MedicationQuery semantics: every statement is constrained to the subject and to
 * the viewer's scope, source is always 'manual', and the public uuid is the only record id callers handle. $today is the
 * family-timezone date computed by the caller in PHP, never the database clock.
 */
class VaccinationQuery
{
    private const FIELDS = ['vaccine', 'given_on', 'next_due_on', 'notes'];

    /**
     * Rows with computed `status` (overdue|due_soon|later|none) and `dueInDays`. Items with a due date first (soonest
     * first), then the rest by date given, newest first.
     *
     * @return Collection<int, MedicalVaccination>
     */
    public function forSubject(Viewer $viewer, MedicalSubject $subject, string $today): Collection
    {
        $rows = Visibility::apply(MedicalVaccination::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderByRaw('next_due_on asc nulls last')
            ->orderByDesc('given_on')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            [$status, $days] = $this->status($row->next_due_on?->format('Y-m-d'), $today);
            $row->setAttribute('status', $status);
            $row->setAttribute('dueInDays', $days);
        }

        return $rows;
    }

    /** Items of this person that need attention: overdue plus due within the soon window. */
    public function attentionCount(Viewer $viewer, MedicalSubject $subject, string $today): int
    {
        $limit = Carbon::createFromFormat('Y-m-d', $today)->addDays((int) config('medical.due_soon_days'))->format('Y-m-d');

        return Visibility::apply(MedicalVaccination::query()->where('subject_id', $subject->getKey()), $viewer)
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<=', $limit)
            ->count();
    }

    public function find(Viewer $viewer, MedicalSubject $subject, string $publicId, AccessLevel $min = AccessLevel::View): ?MedicalVaccination
    {
        if (! Str::isUuid($publicId)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(
            MedicalVaccination::query()->where('public_id', $publicId)->where('subject_id', $subject->getKey()),
            $viewer,
            'subject_id',
            $min,
        )->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Viewer $viewer, MedicalSubject $subject, array $data): MedicalVaccination
    {
        abort_unless($viewer->canEdit(), 403);
        $allowed = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        abort_unless($allowed, 403);

        return $this->insert($viewer, $subject, $data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Viewer $viewer, MedicalSubject $subject, string $publicId, array $data): bool
    {
        if (! Str::isUuid($publicId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $publicId)
            ->update($this->attributes($data) + ['updated_at' => now()]) > 0;
    }

    public function delete(Viewer $viewer, MedicalSubject $subject, string $publicId): bool
    {
        if (! Str::isUuid($publicId) || ! $viewer->canEdit()) {
            return false;
        }

        return $this->scoped($viewer, $subject, $publicId)->delete() > 0;
    }

    /**
     * Records the new dose and clears the old row's next due date in one transaction, so the old reminder goes
     * irrelevant. False (and nothing written) when the old row is not editable by this viewer inside this subject.
     */
    public function markGiven(Viewer $viewer, MedicalSubject $subject, string $publicId, string $givenOn, ?string $nextDueOn): bool
    {
        if (! Str::isUuid($publicId) || ! $viewer->canEdit()) {
            return false;
        }

        return DB::transaction(function () use ($viewer, $subject, $publicId, $givenOn, $nextDueOn): bool {
            $old = $this->scoped($viewer, $subject, $publicId)->lockForUpdate()->first(['vaccine']);
            if ($old === null) {
                return false;
            }
            $cleared = $this->scoped($viewer, $subject, $publicId)->update(['next_due_on' => null, 'updated_at' => now()]);
            if ($cleared === 0) {
                return false;
            }
            $this->insert($viewer, $subject, ['vaccine' => $old->vaccine, 'given_on' => $givenOn, 'next_due_on' => $nextDueOn]);

            return true;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function insert(Viewer $viewer, MedicalSubject $subject, array $data): MedicalVaccination
    {
        $vaccination = new MedicalVaccination;
        $vaccination->forceFill($this->attributes($data) + [
            'subject_id' => $subject->getKey(),
            'source' => 'manual',
            'verified_at' => null,
            'created_by' => $viewer->userId(),
        ])->save();

        return $vaccination;
    }

    /** @return array{0: string, 1: ?int} */
    private function status(?string $dueOn, string $today): array
    {
        if ($dueOn === null) {
            return ['none', null];
        }
        $days = (int) Carbon::createFromFormat('Y-m-d', $today)->startOfDay()->diffInDays(Carbon::createFromFormat('Y-m-d', $dueOn)->startOfDay(), false);

        return match (true) {
            $days < 0 => ['overdue', $days],
            $days <= (int) config('medical.due_soon_days') => ['due_soon', $days],
            default => ['later', $days],
        };
    }

    private function scoped(Viewer $viewer, MedicalSubject $subject, string $publicId): Builder
    {
        return Visibility::apply(
            DB::table('medical_vaccinations')->where('public_id', $publicId)->where('subject_id', $subject->getKey()),
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
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }
}
