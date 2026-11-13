<?php

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;

// A parent-visible account holding one expense, created per call (destroy removes it).
$visibleExpense = function (array $users): array {
    $account = FinanceAccount::factory()->withMembers($users['parent'])->create();

    return ['transaction' => FinanceTransaction::factory()->onAccount($account)->expense(1000)
        ->create(['booked_on' => '2026-03-05', 'description' => 'Matrix'])->id];
};

return [
    'finance.transactions.index' => [
        'method' => 'GET',
        'uri' => '/finance/transactions',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.transactions.store' => [
        'method' => 'POST',
        'uri' => '/finance/transactions',
        'data' => fn (array $users): array => [
            'kind' => 'expense',
            'amount' => '12,34',
            'booked_on' => '2026-03-05',
            'description' => 'Matrix',
            'account_id' => FinanceAccount::factory()->withMembers($users['parent'])->create()->id,
        ],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.transactions.update' => [
        'method' => 'PATCH',
        'uri' => '/finance/transactions/{transaction}',
        'params' => $visibleExpense,
        'data' => ['amount' => '1,00', 'description' => 'Changed'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.transactions.destroy' => [
        'method' => 'DELETE',
        'uri' => '/finance/transactions/{transaction}',
        'params' => $visibleExpense,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
