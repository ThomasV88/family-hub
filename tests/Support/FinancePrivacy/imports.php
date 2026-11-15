<?php

use App\Models\FinanceImportBatch;

return [
    // On A: a draft by the viewer and a committed batch by the co-member (neutral text, these surfaces are visible).
    'visible' => function (array &$ctx): void {
        $ctx['import']['draft'] = FinanceImportBatch::factory()->forAccount($ctx['account'], $ctx['viewer'])
            ->withRows(2, 'Visible import row')->create()->id;
        FinanceImportBatch::factory()->forAccount($ctx['account'], $ctx['other'])->committed()
            ->withRows(1, 'Visible committed row')->create(['added_count' => 1]);
    },

    // Canaries: the co-member's draft on A, a draft and a committed batch on the hidden account B.
    'hidden' => function (array &$ctx): void {
        FinanceImportBatch::factory()->forAccount($ctx['account'], $ctx['other'])
            ->withRows(2, 'ZxCanaryFin draft on A')->create();
        $ctx['hidden']['importDraft'] = FinanceImportBatch::factory()->forAccount($ctx['hiddenAccount'], $ctx['other'])
            ->withRows(2, 'ZxCanaryFin draft on B')->create()->id;
        FinanceImportBatch::factory()->forAccount($ctx['hiddenAccount'], $ctx['other'])->committed()
            ->withRows(2, 'ZxCanaryFin committed on B')->create(['added_count' => 2]);
    },

    'params' => [
        'batch' => fn (array $ctx): string => $ctx['import']['draft'],
    ],

    'hiddenParams' => [
        'batch' => fn (array $ctx): string => $ctx['hidden']['importDraft'],
    ],

    'payloads' => [
        'finance.import.commit' => fn (array $ctx): array => ['include' => []],
    ],
];
