<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceRecurring>
 */
class FinanceRecurringFactory extends Factory
{
    protected $model = FinanceRecurring::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsOn = '2027-01-01';

        return [
            'account_id' => FinanceAccount::factory(),
            'name' => fake()->words(2, true),
            'expected_amount_minor' => 50000,
            'recurrence_rule' => self::monthlyRule($startsOn),
            'starts_on' => $startsOn,
            'schedule_effective_from' => $startsOn,
            'tolerance_minor' => 0,
            'window_days' => 5,
        ];
    }

    public static function monthlyRule(string $startsOn): string
    {
        return RuleBuilder::toRrule(RecurrencePattern::fromArray([
            'kind' => 'monthly_day',
            'monthDay' => (int) substr($startsOn, 8, 2),
        ]));
    }

    /** A monthly rule on the day of the given first due date (keeps the effective-from date in step). */
    public function startingOn(string $startsOn): static
    {
        return $this->state(fn () => [
            'starts_on' => $startsOn,
            'schedule_effective_from' => $startsOn,
            'recurrence_rule' => self::monthlyRule($startsOn),
        ]);
    }

    public function onAccount(FinanceAccount $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }
}
