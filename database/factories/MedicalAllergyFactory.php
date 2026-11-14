<?php

namespace Database\Factories;

use App\Models\MedicalAllergy;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalAllergy>
 */
class MedicalAllergyFactory extends Factory
{
    protected $model = MedicalAllergy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['substance' => 'Peanuts', 'reaction' => 'Hives', 'severity' => 'mild', 'source' => 'manual'];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }

    public function severity(string $severity): static
    {
        return $this->state(fn () => ['severity' => $severity]);
    }
}
