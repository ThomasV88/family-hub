<?php

use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\FinanceTransaction;

// Category names are family-wide and non-sensitive by design, never canaries. Hidden usage is: B's descriptions and
// the hidden-currency budget amount carry the canary needles.
return [
    // A custom category used by an A transaction. 'Pets' exists here too (without usage) so that seeding the hidden
    // usage changes no byte of the category list, which every finance page carries.
    'visible' => function (array &$ctx): void {
        $custom = FinanceCategory::factory()->create(['name' => 'Visible custom']);
        FinanceTransaction::factory()->onAccount($ctx['account'])->expense(2300)
            ->create(['booked_on' => '2026-03-12', 'description' => 'Visible custom spend', 'category_id' => $custom->id]);
        $ctx['category']['visible'] = $custom->id;
        $ctx['category']['pets'] = FinanceCategory::factory()->create(['name' => 'Pets'])->id;
    },

    // 'Pets' used only on B: transactions, a recurring item and a budget in a currency hidden from V.
    'hidden' => function (array &$ctx): void {
        $pets = $ctx['category']['pets'];
        FinanceTransaction::factory()->onAccount($ctx['hiddenAccount'])->expense(777777)
            ->create(['booked_on' => '2026-03-13', 'description' => 'ZxCanaryFin vet', 'category_id' => $pets]);
        FinanceRecurring::factory()->onAccount($ctx['hiddenAccount'])->startingOn('2026-03-05')
            ->create(['name' => 'ZxCanaryFin pet insurance', 'expected_amount_minor' => 777777, 'category_id' => $pets]);
        FinanceBudget::factory()->limit(777777)->create(['category_id' => $pets, 'currency' => 'PLN', 'effective_from' => '2026-03-01']);
    },

    'params' => [
        'category' => fn (array $ctx): string => $ctx['category']['visible'],
    ],

    'panels' => [
        'finance.categories.index' => fn (array $ctx): array => [
            ['cat' => $ctx['category']['visible']],
            ['cat' => 'new'],
        ],
    ],
];
