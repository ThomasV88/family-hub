<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use App\Models\FinanceBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budgets are family-wide limits per category and currency, versioned by effective_from. A viewer reads and writes only
 * the currencies of the accounts they can see, and progress counts only transactions of visible (and selected) accounts.
 */
class BudgetQuery
{
    /**
     * Budgets in force for a month with their viewer-scoped progress, grouped by currency.
     *
     * @return array<string, list<array{id: string, categoryId: string, limitMinor: string, spentMinor: string, percent: int, state: string, overMinor: string|null, effectiveFrom: string}>>
     */
    public function forMonth(Viewer $viewer, string $month, ?string $accountId): array
    {
        $currencies = $this->currencies($viewer, $accountId);
        if ($currencies === []) {
            return [];
        }

        $start = $month.'-01';
        $end = date('Y-m-t', (int) strtotime($start));

        $budgets = DB::table('finance_budgets as b')
            ->whereIn('b.currency', $currencies)
            ->where('b.effective_from', '<=', $start)
            ->whereNotNull('b.amount_minor')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_budgets as n')
                ->whereColumn('n.category_id', 'b.category_id')
                ->whereColumn('n.currency', 'b.currency')
                ->whereColumn('n.effective_from', '>', 'b.effective_from')
                ->where('n.effective_from', '<=', $start))
            ->select(['b.id', 'b.category_id', 'b.currency', 'b.amount_minor', 'b.effective_from'])
            ->orderBy('b.currency')
            ->orderBy('b.category_id')
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        [$kindSql, $kindBindings] = DisplayKind::sql($viewer, 't');
        $spentQuery = Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->where('t.booked_on', '>=', $start)
            ->where('t.booked_on', '<=', $end)
            ->whereIn('t.currency', $currencies)
            ->whereIn('t.category_id', $budgets->pluck('category_id')->unique()->values()->all())
            ->whereRaw($kindSql.' = ?', [...$kindBindings, 'expense']);
        if ($accountId !== null) {
            $spentQuery->where('t.account_id', $accountId);
        }
        $spentRows = $spentQuery
            ->groupBy('t.category_id', 't.currency')
            ->selectRaw('t.category_id, t.currency, (-SUM(t.amount_minor))::bigint as spent')
            ->get();

        $spent = [];
        foreach ($spentRows as $row) {
            $spent[$row->currency.'|'.$row->category_id] = (int) $row->spent;
        }

        $out = [];
        foreach ($budgets as $b) {
            $limit = (int) $b->amount_minor;
            $used = $spent[$b->currency.'|'.$b->category_id] ?? 0;
            $over = $used > $limit;
            $out[(string) $b->currency][] = [
                'id' => (string) $b->id,
                'categoryId' => (string) $b->category_id,
                'limitMinor' => (string) $limit,
                'spentMinor' => (string) $used,
                'percent' => intdiv($used * 100, $limit),
                'state' => $over ? 'over' : ($used * 100 >= $limit * 90 ? 'near' : 'ok'),
                'overMinor' => $over ? (string) ($used - $limit) : null,
                'effectiveFrom' => substr((string) $b->effective_from, 0, 7),
            ];
        }

        return $out;
    }

    /** The budget version, or null when unknown or in a currency the viewer has no visible account in. */
    public function find(Viewer $viewer, string $id): ?FinanceBudget
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return FinanceBudget::query()
            ->where('id', $id)
            ->whereIn('currency', $this->visibleCurrencies($viewer))
            ->first();
    }

    /** The limit in force for a month (a tombstone or nothing yields null). */
    public function activeFor(string $categoryId, string $currency, string $month): ?FinanceBudget
    {
        $row = FinanceBudget::query()
            ->where('category_id', $categoryId)
            ->where('currency', $currency)
            ->where('effective_from', '<=', $month.'-01')
            ->orderByDesc('effective_from')
            ->first();

        return $row !== null && $row->amount_minor !== null ? $row : null;
    }

    /** The version row that starts exactly in the given month, if any. */
    public function versionAt(string $categoryId, string $currency, string $month): ?FinanceBudget
    {
        return FinanceBudget::query()
            ->where('category_id', $categoryId)
            ->where('currency', $currency)
            ->where('effective_from', $month.'-01')
            ->first();
    }

    public function hasEarlier(string $categoryId, string $currency, string $month): bool
    {
        return FinanceBudget::query()
            ->where('category_id', $categoryId)
            ->where('currency', $currency)
            ->where('effective_from', '<', $month.'-01')
            ->exists();
    }

    /**
     * Sets the limit from a month on (null amount writes a tombstone). The row is created when missing, then changed by
     * a statement restricted to the currencies of the viewer's visible accounts.
     */
    public function upsertVersion(Viewer $viewer, string $categoryId, string $currency, string $fromMonth, ?int $amountMinor): void
    {
        if (! in_array($currency, $this->visibleCurrencies($viewer), true)) {
            return;
        }

        $date = $fromMonth.'-01';
        $now = now();
        DB::table('finance_budgets')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'category_id' => $categoryId,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'effective_from' => $date,
            'created_by' => $viewer->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('finance_budgets')
            ->where('category_id', $categoryId)
            ->where('currency', $currency)
            ->where('effective_from', $date)
            ->whereIn('currency', Visibility::apply(DB::table('finance_accounts')->select('currency'), $viewer, 'id'))
            ->update(['amount_minor' => $amountMinor, 'updated_at' => $now]);
    }

    public function deleteVersion(Viewer $viewer, string $id): int
    {
        if (! Str::isUuid($id)) {
            return 0;
        }

        return DB::table('finance_budgets')
            ->where('id', $id)
            ->whereIn('currency', Visibility::apply(DB::table('finance_accounts')->select('currency'), $viewer, 'id'))
            ->delete();
    }

    /** @return list<string> */
    private function visibleCurrencies(Viewer $viewer): array
    {
        return Visibility::apply(DB::table('finance_accounts'), $viewer, 'id')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    /** @return list<string> */
    private function currencies(Viewer $viewer, ?string $accountId): array
    {
        if ($accountId === null) {
            return $this->visibleCurrencies($viewer);
        }

        return Visibility::apply(DB::table('finance_accounts')->where('id', $accountId), $viewer, 'id')
            ->pluck('currency')
            ->map(fn ($c) => (string) $c)
            ->all();
    }
}
