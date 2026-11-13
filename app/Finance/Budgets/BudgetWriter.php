<?php

namespace App\Finance\Budgets;

use App\Finance\Queries\BudgetQuery;
use App\Finance\Viewer;
use App\Models\FinanceBudget;
use Illuminate\Support\Facades\DB;

/** Budget changes go through BudgetQuery's scoped mutation methods only. */
class BudgetWriter
{
    public function __construct(private readonly BudgetQuery $budgets) {}

    public function save(Viewer $viewer, string $categoryId, string $currency, string $fromMonth, int $amountMinor): void
    {
        DB::transaction(fn () => $this->budgets->upsertVersion($viewer, $categoryId, $currency, $fromMonth, $amountMinor));
    }

    /**
     * Ends the budget from a month on. When the version starting that month is the first one ever, removing it is
     * equivalent to a tombstone and keeps the table tidy; otherwise a tombstone is written.
     */
    public function remove(Viewer $viewer, FinanceBudget $budget, string $fromMonth): void
    {
        $category = (string) $budget->category_id;
        $currency = (string) $budget->currency;

        DB::transaction(function () use ($viewer, $category, $currency, $fromMonth) {
            $exact = $this->budgets->versionAt($category, $currency, $fromMonth);
            if ($exact !== null && ! $this->budgets->hasEarlier($category, $currency, $fromMonth)) {
                $this->budgets->deleteVersion($viewer, (string) $exact->id);

                return;
            }
            $this->budgets->upsertVersion($viewer, $category, $currency, $fromMonth, null);
        });
    }
}
