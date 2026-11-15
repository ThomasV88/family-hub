<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WishOccasion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishOccasion>
 */
class WishOccasionFactory extends Factory
{
    protected $model = WishOccasion::class;

    /** @return array<string, mixed> custom yearly occasion for the whole family */
    public function definition(): array
    {
        return [
            'owner_id' => null,
            'kind' => WishOccasion::CUSTOM,
            'title' => fake()->words(2, true),
            'month' => 6,
            'day' => 1,
        ];
    }

    public function nameday(string $calendar = 'sk', string $name = 'Ľubomír', int $month = 11, int $day = 9): static
    {
        return $this->state(fn () => [
            'owner_id' => User::factory(),
            'kind' => WishOccasion::NAMEDAY,
            'title' => null,
            'calendar' => $calendar,
            'name' => $name,
            'month' => $month,
            'day' => $day,
        ]);
    }
}
