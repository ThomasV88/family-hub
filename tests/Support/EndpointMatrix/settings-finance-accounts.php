<?php

use App\Models\FinanceAccount;

$nonAdmins = ['parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'settings.finance_accounts.index' => [
        'method' => 'GET',
        'uri' => '/settings/finance-accounts',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.finance_accounts.store' => [
        'method' => 'POST',
        'uri' => '/settings/finance-accounts',
        'data' => ['name' => 'Matrix acct', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '', 'balance_as_of' => '2026-01-01', 'member_ids' => []],
        'expect' => ['guest' => 302, 'admin' => 303] + $nonAdmins,
    ],
    'settings.finance_accounts.update' => [
        'method' => 'PATCH',
        'uri' => '/settings/finance-accounts/{account}',
        'params' => fn (array $users) => ['account' => FinanceAccount::factory()->create()->id],
        'data' => ['name' => 'Changed', 'type' => 'bank', 'member_ids' => []],
        'expect' => ['guest' => 302, 'admin' => 303] + $nonAdmins,
    ],
];
