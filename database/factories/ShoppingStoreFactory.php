<?php

namespace Database\Factories;

use App\Models\ShoppingStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingStore>
 */
class ShoppingStoreFactory extends Factory
{
    protected $model = ShoppingStore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->company()];
    }
}
