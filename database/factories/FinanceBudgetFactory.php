<?php

namespace Database\Factories;

use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceBudget>
 */
class FinanceBudgetFactory extends Factory
{
    protected $model = FinanceBudget::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'category_id' => FinanceCategory::factory(),
            'currency' => 'EUR',
            'amount_minor' => 40000,
            'effective_from' => '2026-03-01',
        ];
    }

    public function limit(int $amountMinor): static
    {
        return $this->state(fn () => ['amount_minor' => $amountMinor]);
    }

    public function tombstone(): static
    {
        return $this->state(fn () => ['amount_minor' => null]);
    }
}
