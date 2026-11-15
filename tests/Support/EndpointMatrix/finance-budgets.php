<?php

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;

// A parent-visible EUR account and an EUR Groceries budget, created per call (destroy removes it).
$visibleBudget = function (array $users): array {
    FinanceAccount::factory()->withMembers($users['parent'])->create();

    return ['budget' => FinanceBudget::factory()->limit(40000)->create([
        'category_id' => FinanceCategory::factory()->create()->id, 'currency' => 'EUR', 'effective_from' => '2026-03-01',
    ])->id];
};

$denied = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'finance.budgets.store' => [
        'method' => 'POST',
        'uri' => '/finance/budgets',
        'data' => function (array $users): array {
            FinanceAccount::factory()->withMembers($users['parent'])->create();

            return [
                'category_id' => FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail()->id,
                'currency' => 'EUR',
                'amount' => '400,00',
                'from_month' => '2026-03',
            ];
        },
        'expect' => $denied,
    ],
    'finance.budgets.update' => [
        'method' => 'PATCH',
        'uri' => '/finance/budgets/{budget}',
        'params' => $visibleBudget,
        'data' => ['amount' => '500,00', 'from_month' => '2026-05'],
        'expect' => $denied,
    ],
    'finance.budgets.destroy' => [
        'method' => 'DELETE',
        'uri' => '/finance/budgets/{budget}',
        'params' => $visibleBudget,
        'data' => ['from_month' => '2026-06'],
        'expect' => $denied,
    ],
];
