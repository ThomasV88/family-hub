<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipePhoto>
 */
class RecipePhotoFactory extends Factory
{
    protected $model = RecipePhoto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->uuid();

        return [
            'recipe_id' => Recipe::factory(),
            'path' => "recipes/{$name}.jpg",
            'thumb_path' => "recipes/{$name}_thumb.jpg",
            'position' => 0,
            'is_main' => false,
            'version' => 1,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main' => true]);
    }
}
