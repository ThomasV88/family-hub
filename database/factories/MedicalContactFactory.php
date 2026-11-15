<?php

namespace Database\Factories;

use App\Models\MedicalContact;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalContact>
 */
class MedicalContactFactory extends Factory
{
    protected $model = MedicalContact::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Contact', 'relation' => 'Mother', 'phone' => '+421 900 111 222', 'sort' => 0];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }
}
