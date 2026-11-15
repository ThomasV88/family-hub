<?php

use App\Models\FinanceTransaction;

return [
    'visible' => function (array &$ctx): void {
        $ctx['visibleIncome'] = FinanceTransaction::factory()->onAccount($ctx['account'])->income(250000)
            ->create(['booked_on' => '2026-03-01', 'description' => 'Salary March'])->id;
        $ctx['visibleExpense'] = FinanceTransaction::factory()->onAccount($ctx['account'])->expense(4500)
            ->create(['booked_on' => '2026-03-02', 'description' => 'Groceries'])->id;
    },

    // The canary: -777777 minor units (7777.77) and a recognisable payee, on an account the viewer cannot see.
    'hidden' => function (array &$ctx): void {
        $ctx['hidden']['expense'] = FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->expense(777777)
            ->create(['booked_on' => '2026-03-03', 'description' => 'ZxCanaryFin payee'])->id;
    },

    'params' => [
        'account' => fn (array $ctx): string => $ctx['account']->id,
    ],

    'hiddenParams' => [
        'account' => fn (array $ctx): string => $ctx['hiddenAccount']->id,
    ],
];
