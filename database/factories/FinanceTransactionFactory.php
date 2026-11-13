<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceTransaction>
 */
class FinanceTransactionFactory extends Factory
{
    protected $model = FinanceTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'account_id' => FinanceAccount::factory(),
            'currency' => 'EUR',
            'kind' => 'expense',
            'amount_minor' => -1250,
            'booked_on' => '2026-03-15',
            'description' => fake()->words(2, true),
            'source' => 'manual',
        ];
    }

    public function income(int $amountMinor = 150000): static
    {
        return $this->state(fn () => ['kind' => 'income', 'amount_minor' => abs($amountMinor)]);
    }

    public function expense(int $amountMinor = 1250): static
    {
        return $this->state(fn () => ['kind' => 'expense', 'amount_minor' => -abs($amountMinor)]);
    }

    public function onAccount(FinanceAccount $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id, 'currency' => $account->currency]);
    }
}
