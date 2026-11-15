<?php

namespace Database\Factories;

use App\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceCategory>
 */
class FinanceCategoryFactory extends Factory
{
    protected $model = FinanceCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => 'expense',
            'default_key' => null,
            'name' => fake()->unique()->bothify('Cat ????-####'),
        ];
    }

    public function income(): static
    {
        return $this->state(fn () => ['kind' => 'income']);
    }
}
