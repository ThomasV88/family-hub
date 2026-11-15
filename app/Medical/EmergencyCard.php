<?php

namespace App\Medical;

use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\ContactQuery;
use App\Medical\Queries\MedicationQuery;
use App\Models\MedicalAllergy;
use App\Models\MedicalContact;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use Illuminate\Support\Carbon;

/**
 * Props of the Health/EmergencyCard page. Safety data only: all allergies, medications taken today, blood type and
 * emergency contacts. No notes, diagnoses or visits. Everything is read through the scoped queries with the View scope.
 */
class EmergencyCard
{
    public function __construct(private readonly AllergyQuery $allergies, private readonly MedicationQuery $medications, private readonly ContactQuery $contacts) {}

    /** @return array<string, mixed> */
    public function props(Viewer $viewer, MedicalSubject $subject, string $today): array
    {
        $birth = $subject->birth_date?->format('Y-m-d');

        return [
            'person' => [
                'id' => (string) $subject->getKey(),
                'name' => $subject->name,
                'ageYears' => $birth === null ? null : max(0, (int) Carbon::createFromFormat('Y-m-d', $birth, (string) config('family.timezone'))->startOfDay()->diffInYears(Carbon::createFromFormat('Y-m-d', $today, (string) config('family.timezone'))->startOfDay(), true)),
                'birthDate' => $birth,
                'bloodType' => $subject->blood_type,
            ],
            'allergies' => $this->allergies->forSubject($viewer, $subject)->map(fn (MedicalAllergy $a) => [
                'substance' => $a->substance,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
            ])->values()->all(),
            'medications' => $this->medications->current($viewer, $subject, $today)->map(fn (MedicalMedication $m) => [
                'name' => $m->name,
                'dose' => $m->dose,
                'schedule' => $m->schedule,
            ])->values()->all(),
            'contacts' => $this->contacts->forSubject($viewer, $subject)->map(fn (MedicalContact $c) => [
                'name' => $c->name,
                'relation' => $c->relation,
                'phone' => $c->phone,
            ])->values()->all(),
            'printedOn' => $today,
        ];
    }
}
