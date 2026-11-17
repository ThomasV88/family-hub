<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\User;
use App\Recipes\RecipeSearchKey;
use App\Recipes\RecipeVisibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'author_id' => User::factory(),
            'title' => $title,
            'title_key' => RecipeSearchKey::title($title),
            'search_key' => RecipeSearchKey::title($title),
            'visibility' => RecipeVisibility::Private,
            'servings' => 4,
            'created_via' => 'manual',
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['visibility' => RecipeVisibility::Shared]);
    }
}
