<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingUnit;
use App\Recipes\RecipeSearchKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeIngredient>
 */
class RecipeIngredientFactory extends Factory
{
    protected $model = RecipeIngredient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'recipe_id' => Recipe::factory(),
            'position' => 0,
            'name' => $name,
            'name_key' => RecipeSearchKey::ingredient($name),
            'quantity' => 1,
            'unit_id' => null,
        ];
    }

    /** A built-in shopping unit looked up by key, never by id. */
    public function unit(string $key): static
    {
        return $this->state(fn () => ['unit_id' => ShoppingUnit::where('key', $key)->value('id')]);
    }
}
