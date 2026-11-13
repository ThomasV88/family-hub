<?php

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;

$groceries = fn (): FinanceCategory => FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();

return [
    // An EUR Groceries budget from March with Groceries spending on the visible account A.
    'visible' => function (array &$ctx) use ($groceries): void {
        $ctx['budget']['visible'] = FinanceBudget::factory()->limit(40000)->create([
            'category_id' => $groceries()->id, 'currency' => 'EUR', 'effective_from' => '2026-03-01',
        ])->id;
        FinanceTransaction::factory()->onAccount($ctx['account'])->expense(12000)
            ->create(['booked_on' => '2026-03-09', 'description' => 'Groceries visible', 'category_id' => $groceries()->id]);
    },

    // A PLN account only the other member can see, with a PLN budget and spending, plus Groceries spending on B.
    'hidden' => function (array &$ctx) use ($groceries): void {
        $pln = FinanceAccount::factory()->withMembers($ctx['other'])->create(['name' => 'ZxCanaryFin PLN', 'currency' => 'PLN']);
        $ctx['budget']['hidden'] = FinanceBudget::factory()->limit(777777)->create([
            'category_id' => $groceries()->id, 'currency' => 'PLN', 'effective_from' => '2026-03-01',
        ])->id;
        FinanceTransaction::factory()->onAccount($pln)->expense(777777)
            ->create(['booked_on' => '2026-03-10', 'description' => 'ZxCanaryFin PLN groceries', 'category_id' => $groceries()->id]);
        FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->expense(777777)
            ->create(['booked_on' => '2026-03-11', 'description' => 'ZxCanaryFin groceries', 'category_id' => $groceries()->id]);
    },

    'params' => [
        'budget' => fn (array $ctx): string => $ctx['budget']['visible'],
    ],

    'hiddenParams' => [
        'budget' => fn (array $ctx): string => $ctx['budget']['hidden'],
    ],

    'payloads' => [
        'finance.budgets.update' => fn (array $ctx): array => ['amount' => '1,00', 'from_month' => '2026-03'],
        'finance.budgets.destroy' => fn (array $ctx): array => ['from_month' => '2026-03'],
    ],
];
