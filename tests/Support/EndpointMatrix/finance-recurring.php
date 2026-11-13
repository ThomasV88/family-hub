<?php

use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;

// A parent-visible account holding one recurring item, created per call (destroy removes it).
$visibleRecurring = function (array $users): array {
    $account = FinanceAccount::factory()->withMembers($users['parent'])->create();

    return ['recurring' => FinanceRecurring::factory()->onAccount($account)->create(['name' => 'Matrix rent'])->id];
};

return [
    'finance.recurring.index' => [
        'method' => 'GET',
        'uri' => '/finance/recurring',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.recurring.store' => [
        'method' => 'POST',
        'uri' => '/finance/recurring',
        'data' => fn (array $users): array => [
            'name' => 'Matrix rent',
            'amount' => '450,00',
            'account_id' => FinanceAccount::factory()->withMembers($users['parent'])->create()->id,
            'starts_on' => '2027-01-05',
            'recurrence' => ['kind' => 'monthly_day'],
            'window_days' => 5,
        ],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.recurring.update' => [
        'method' => 'PATCH',
        'uri' => '/finance/recurring/{recurring}',
        'params' => $visibleRecurring,
        'data' => ['name' => 'Changed'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.recurring.destroy' => [
        'method' => 'DELETE',
        'uri' => '/finance/recurring/{recurring}',
        'params' => $visibleRecurring,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
