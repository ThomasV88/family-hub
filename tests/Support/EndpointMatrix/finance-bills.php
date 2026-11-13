<?php

use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Models\FinanceTransaction;

$today = fn (): string => now((string) config('family.timezone'))->toDateString();

// A parent-visible account holding one recurring item that is due today, created per call.
$openBill = function (array $users) use ($today): array {
    $account = FinanceAccount::factory()->withMembers($users['parent'])->create();
    $item = FinanceRecurring::factory()->onAccount($account)->startingOn($today())->create(['name' => 'Matrix bill']);

    return ['recurring' => $item->id, 'date' => $today()];
};

$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'finance.bills.record' => [
        'method' => 'POST',
        'uri' => '/finance/recurring/{recurring}/bills/{date}/record',
        'params' => $openBill,
        'data' => ['amount' => '500,00'],
        'expect' => $expect,
    ],
    'finance.bills.match' => [
        'method' => 'POST',
        'uri' => '/finance/recurring/{recurring}/bills/{date}/match',
        'params' => $openBill,
        'data' => fn (array $users, array $params): array => ['transaction_id' => FinanceTransaction::factory()
            ->onAccount(FinanceAccount::query()->findOrFail(FinanceRecurring::query()->findOrFail($params['recurring'])->account_id))
            ->expense(50000)->create(['booked_on' => $params['date'], 'description' => 'Matrix payment'])->id],
        'expect' => $expect,
    ],
    'finance.bills.unmatch' => [
        'method' => 'DELETE',
        'uri' => '/finance/recurring/{recurring}/bills/{date}/match',
        'params' => $openBill,
        'expect' => $expect,
    ],
];
