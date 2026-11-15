<?php

namespace Database\Factories;

use App\Access\AccessLevel;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<MedicalSubject>
 */
class MedicalSubjectFactory extends Factory
{
    protected $model = MedicalSubject::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->firstName(),
            'sex' => 'unspecified',
            'self_access_enabled' => true,
            'ai_consent' => false,
        ];
    }

    public function withMember(User $user, AccessLevel $level = AccessLevel::Edit): static
    {
        return $this->afterCreating(function (MedicalSubject $subject) use ($user, $level) {
            DB::table('medical_subject_members')->insert([
                'subject_id' => $subject->id,
                'user_id' => $user->id,
                'level' => $level->value,
                'granted_by' => null,
                'created_at' => now(),
            ]);
        });
    }

    public function linkedTo(User $user, bool $selfAccess = true): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'self_access_enabled' => $selfAccess]);
    }
}
