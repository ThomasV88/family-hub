<?php

namespace Database\Factories;

use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalMedication>
 */
class MedicalMedicationFactory extends Factory
{
    protected $model = MedicalMedication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => 'Twice a day', 'started_on' => null, 'ended_on' => null, 'source' => 'manual'];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }

    public function ongoing(): static
    {
        return $this->state(fn () => ['ended_on' => null]);
    }

    public function ended(string $date): static
    {
        return $this->state(fn () => ['ended_on' => $date]);
    }
}
