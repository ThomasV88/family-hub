<?php

namespace Database\Factories;

use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalVisit>
 */
class MedicalVisitFactory extends Factory
{
    protected $model = MedicalVisit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visited_on' => '2026-01-10',
            'doctor' => 'Dr. Test',
            'specialty_key' => 'general_practice',
            'source' => 'manual',
        ];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }

    public function specialty(string $key): static
    {
        return $this->state(fn () => ['specialty_key' => $key, 'specialty_other' => null]);
    }

    public function other(string $label): static
    {
        return $this->state(fn () => ['specialty_key' => 'other', 'specialty_other' => $label]);
    }
}
