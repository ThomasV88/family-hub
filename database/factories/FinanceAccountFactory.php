<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceAccount>
 */
class FinanceAccountFactory extends Factory
{
    protected $model = FinanceAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => 'bank',
            'currency' => 'EUR',
            'opening_balance_minor' => 0,
            'balance_as_of' => '2020-01-01',
        ];
    }

    public function withMembers(User ...$users): static
    {
        return $this->afterCreating(function (FinanceAccount $account) use ($users) {
            $account->members()->attach(array_map(fn (User $u) => $u->id, $users), ['created_at' => now()]);
        });
    }
}
