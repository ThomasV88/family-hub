<?php

namespace Database\Factories;

use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceRecurringMatch>
 */
class FinanceRecurringMatchFactory extends Factory
{
    protected $model = FinanceRecurringMatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'matched_by' => 'auto',
            'expected_amount_minor' => 50000,
        ];
    }

    /** The account comes from the recurring item, so a cross-account match is only built by overriding it. */
    public function between(FinanceRecurring $recurring, string $dueDate, FinanceTransaction $transaction): static
    {
        return $this->state(fn () => [
            'recurring_id' => $recurring->id,
            'account_id' => $recurring->account_id,
            'due_date' => $dueDate,
            'transaction_id' => $transaction->id,
            'expected_amount_minor' => $recurring->expected_amount_minor,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => ['matched_by' => 'manual']);
    }
}
