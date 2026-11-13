<?php

// The Overview and Home read what budgets.php and recurring.php seed (visible and hidden); this area adds only panel variants.
return [
    'panels' => [
        'finance.index' => fn (array $ctx): array => [
            ['budget' => $ctx['budget']['visible']],
            ['budget' => 'new'],
            ['month' => '2026-02'],
            ['month' => '2026-03', 'account' => $ctx['account']->id],
        ],
    ],
];
