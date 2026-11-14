<?php

namespace Database\Factories;

use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MedicalVaccination>
 */
class MedicalVaccinationFactory extends Factory
{
    protected $model = MedicalVaccination::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'vaccine' => 'Tetanus', 'given_on' => '2020-01-01', 'next_due_on' => null, 'source' => 'manual'];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }

    public function due(string $date): static
    {
        return $this->state(fn () => ['next_due_on' => $date]);
    }

    public function given(string $date): static
    {
        return $this->state(fn () => ['given_on' => $date]);
    }
}
