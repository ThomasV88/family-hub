<?php

use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;

return [
    // A monthly rent on A from March 2026 with one auto match, plus an item that is due around today.
    'visible' => function (array &$ctx): void {
        $item = FinanceRecurring::factory()->onAccount($ctx['account'])->startingOn('2026-03-05')
            ->create(['name' => 'Visible rent', 'expected_amount_minor' => 45000]);
        $paid = FinanceTransaction::factory()->onAccount($ctx['account'])->expense(45000)
            ->create(['booked_on' => '2026-03-06', 'description' => 'Landlord']);
        FinanceRecurringMatch::factory()->between($item, '2026-03-05', $paid)->create();
        FinanceRecurring::factory()->onAccount($ctx['account'])->startingOn(now(config('family.timezone'))->subDays(3)->toDateString())
            ->create(['name' => 'Visible energy', 'expected_amount_minor' => 9000]);
        $ctx['recurring']['visible'] = $item->id;
        $ctx['recurring']['visibleDate'] = '2026-03-05';
    },

    // The hidden twin: a canary rent with a match on B, and one that is overdue today on B.
    'hidden' => function (array &$ctx): void {
        $item = FinanceRecurring::factory()->onAccount($ctx['hiddenAccount'])->startingOn('2026-03-05')
            ->create(['name' => 'ZxCanaryFin rent', 'expected_amount_minor' => 777777]);
        $paid = FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->expense(777777)
            ->create(['booked_on' => '2026-03-06', 'description' => 'ZxCanaryFin landlord']);
        FinanceRecurringMatch::factory()->between($item, '2026-03-05', $paid)->create();
        FinanceRecurring::factory()->onAccount($ctx['hiddenAccount'])->startingOn(now(config('family.timezone'))->subDays(3)->toDateString())
            ->create(['name' => 'ZxCanaryFin energy', 'expected_amount_minor' => 777777]);
        $ctx['recurring']['hidden'] = $item->id;
    },

    'params' => [
        'recurring' => fn (array $ctx): string => $ctx['recurring']['visible'],
        'date' => fn (array $ctx): string => $ctx['recurring']['visibleDate'],
    ],

    'hiddenParams' => [
        'recurring' => fn (array $ctx): string => $ctx['recurring']['hidden'],
    ],

    'panels' => [
        'finance.recurring.index' => fn (array $ctx): array => [
            ['rec' => $ctx['recurring']['visible']],
            ['rec' => 'new'],
            ['bill' => $ctx['recurring']['visible'].'.'.$ctx['recurring']['visibleDate']],
            ['day' => $ctx['recurring']['visibleDate']],
            ['month' => '2026-04'],
            ['month' => '2026-03', 'account' => $ctx['account']->id],
        ],
    ],

    'payloads' => [
        'finance.recurring.update' => fn (array $ctx): array => ['name' => 'x'],
    ],
];
