<?php

namespace Database\Factories;

use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Shopping\ItemState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingItem>
 */
class ShoppingItemFactory extends Factory
{
    protected $model = ShoppingItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'list_id' => ShoppingList::factory(),
            'name' => fake()->words(2, true),
            'quantity' => 1,
            'state' => ItemState::Pending,
            'position' => 0,
        ];
    }

    public function bought(): static
    {
        return $this->state(fn () => ['state' => ItemState::Bought, 'state_changed_at' => now()]);
    }

    public function unavailable(): static
    {
        return $this->state(fn () => ['state' => ItemState::Unavailable, 'state_changed_at' => now()]);
    }
}
