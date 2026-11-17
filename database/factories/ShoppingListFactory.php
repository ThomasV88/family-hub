<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\User;
use App\Shopping\ListStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingList>
 */
class ShoppingListFactory extends Factory
{
    protected $model = ShoppingList::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'status' => ListStatus::Open,
            'creator_id' => User::factory(),
            'responsible_id' => fn (array $attrs) => $attrs['creator_id'],
        ];
    }

    public function locked(): static
    {
        return $this->state(fn () => ['status' => ListStatus::Locked, 'locked_at' => now()]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => ListStatus::Done,
            'locked_at' => now()->subHour(),
            'done_at' => now(),
        ]);
    }
}
