<?php

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use Illuminate\Support\Str;

return [
    // On A: an expense, an income and a transfer A -> A2 (A2 visible to the viewer too).
    'visible' => function (array &$ctx): void {
        $a2 = FinanceAccount::factory()->withMembers($ctx['viewer'], $ctx['other'])->create(['name' => 'Visible Second']);
        $ctx['account2'] = $a2;
        $ctx['tx']['expense'] = FinanceTransaction::factory()->onAccount($ctx['account'])->expense(3300)
            ->create(['booked_on' => '2026-03-06', 'description' => 'Pharmacy'])->id;
        FinanceTransaction::factory()->onAccount($ctx['account'])->income(90000)
            ->create(['booked_on' => '2026-03-07', 'description' => 'Refund']);
        $transferId = (string) Str::uuid7();
        FinanceTransaction::factory()->onAccount($ctx['account'])->create([
            'kind' => 'transfer', 'amount_minor' => -5000, 'transfer_id' => $transferId, 'booked_on' => '2026-03-08', 'description' => 'To second',
        ]);
        FinanceTransaction::factory()->onAccount($a2)->create([
            'kind' => 'transfer', 'amount_minor' => 5000, 'transfer_id' => $transferId, 'booked_on' => '2026-03-08', 'description' => 'To second',
        ]);
    },

    // Canary expenses and a transfer between two accounts the viewer cannot see.
    'hidden' => function (array &$ctx): void {
        $b2 = FinanceAccount::factory()->withMembers($ctx['other'])->create(['name' => 'ZxCanaryFin Hidden Second']);
        $ctx['hidden']['expense2'] = FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->expense(777777)
            ->create(['booked_on' => '2026-03-04', 'description' => 'ZxCanaryFin payee'])->id;
        $transferId = (string) Str::uuid7();
        FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->create([
            'kind' => 'transfer', 'amount_minor' => -777777, 'transfer_id' => $transferId, 'booked_on' => '2026-03-05', 'description' => 'ZxCanaryFin move',
        ]);
        FinanceTransaction::factory()->onAccount($b2)->create([
            'kind' => 'transfer', 'amount_minor' => 777777, 'transfer_id' => $transferId, 'booked_on' => '2026-03-05', 'description' => 'ZxCanaryFin move',
        ]);
    },

    'params' => [
        'transaction' => fn (array $ctx): string => $ctx['tx']['expense'],
    ],

    'hiddenParams' => [
        'transaction' => fn (array $ctx): string => $ctx['hidden']['expense'],
    ],

    'panels' => [
        'finance.transactions.index' => fn (array $ctx): array => [
            ['month' => '2026-03', 'tx' => $ctx['tx']['expense']],
            ['month' => '2026-03', 'tx' => 'new'],
            ['month' => '2026-03', 'account' => $ctx['account']->id],
            ['month' => '2026-03', 'q' => 'a'],
            ['month' => '2026-03', 'type' => 'transfer'],
            ['month' => '2026-03', 'category' => 'none'],
        ],
    ],

    'payloads' => [
        'finance.transactions.update' => fn (array $ctx): array => ['amount' => '1,00', 'booked_on' => '2026-01-15', 'description' => 'x'],
    ],
];
