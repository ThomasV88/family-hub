<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use Illuminate\Support\Facades\DB;

/**
 * Overview aggregates, always per currency, summed in SQL (bigint, no float) over the viewer's visible accounts only.
 * Income and spending go through DisplayKind: fully visible transfers are excluded, a transfer leg with a hidden
 * counterpart counts as plain income or expense by its sign.
 */
class OverviewQuery
{
    /**
     * @return array<string, array{incomeMinor: string, spendingMinor: string, balanceMinor: string}> keyed by currency
     */
    public function summary(Viewer $viewer, string $month, ?string $accountId): array
    {
        [$start, $end] = $this->bounds($month);
        $currencies = $this->currencies($viewer, $accountId);

        [$kindSql, $kindBindings] = DisplayKind::sql($viewer, 't');
        $flows = Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->where('t.booked_on', '>=', $start)
            ->where('t.booked_on', '<=', $end)
            ->select('t.currency', 't.amount_minor')
            ->selectRaw($kindSql.' as dk', $kindBindings);
        if ($accountId !== null) {
            $flows->where('t.account_id', $accountId);
        }
        $rows = DB::query()->fromSub($flows, 's')
            ->groupBy('s.currency')
            ->selectRaw("s.currency, COALESCE(SUM(CASE WHEN s.dk = 'income' THEN s.amount_minor ELSE 0 END), 0)::bigint as income, COALESCE(-SUM(CASE WHEN s.dk = 'expense' THEN s.amount_minor ELSE 0 END), 0)::bigint as spending")
            ->get();
        $flow = [];
        foreach ($rows as $row) {
            $flow[(string) $row->currency] = [(int) $row->income, (int) $row->spending];
        }

        $balances = $this->balances($viewer, $end, $accountId);

        $out = [];
        foreach ($currencies as $currency) {
            $out[$currency] = [
                'incomeMinor' => (string) ($flow[$currency][0] ?? 0),
                'spendingMinor' => (string) ($flow[$currency][1] ?? 0),
                'balanceMinor' => (string) ($balances[$currency] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Spending by category per currency: top 7 by amount (Uncategorized is categoryId null), the rest as "other" and the full table.
     *
     * @return array<string, array{totalMinor: string, top: list<array<string, mixed>>, other: array<string, mixed>|null, all: list<array<string, mixed>>}>
     */
    public function categorySpending(Viewer $viewer, string $month, ?string $accountId): array
    {
        [$start, $end] = $this->bounds($month);

        [$kindSql, $kindBindings] = DisplayKind::sql($viewer, 't');
        $query = Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->where('t.booked_on', '>=', $start)
            ->where('t.booked_on', '<=', $end)
            ->whereRaw($kindSql.' = ?', [...$kindBindings, 'expense']);
        if ($accountId !== null) {
            $query->where('t.account_id', $accountId);
        }
        $rows = $query
            ->groupBy('t.currency', 't.category_id')
            ->selectRaw('t.currency, t.category_id, (-SUM(t.amount_minor))::bigint as spent')
            ->orderBy('t.currency')
            ->orderByRaw('(-SUM(t.amount_minor)) desc')
            ->orderByRaw('t.category_id asc nulls last')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            if ((int) $row->spent > 0) {
                $byCurrency[(string) $row->currency][] = [$row->category_id === null ? null : (string) $row->category_id, (int) $row->spent];
            }
        }

        $out = [];
        foreach ($this->currencies($viewer, $accountId) as $currency) {
            $items = $byCurrency[$currency] ?? [];
            $total = array_sum(array_column($items, 1));
            $all = array_map(fn (array $i) => [
                'categoryId' => $i[0],
                'amountMinor' => (string) $i[1],
                'percent' => $this->percent($i[1], $total),
            ], $items);
            $rest = array_slice($items, 7);
            $restSum = array_sum(array_column($rest, 1));

            $out[$currency] = [
                'totalMinor' => (string) $total,
                'top' => array_slice($all, 0, 7),
                'other' => $rest === [] ? null : ['amountMinor' => (string) $restSum, 'percent' => $this->percent($restSum, $total)],
                'all' => $all,
            ];
        }

        return $out;
    }

    /**
     * Month-end balances (opening balances plus all rows booked up to each month end), $months entries ending at $month.
     * A month without rows repeats the previous balance.
     *
     * @return array<string, list<array{month: string, balanceMinor: string}>>
     */
    public function balanceSeries(Viewer $viewer, string $month, ?string $accountId, int $months = 12): array
    {
        [, $end] = $this->bounds($month);

        $net = Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->where('t.booked_on', '<=', $end);
        if ($accountId !== null) {
            $net->where('t.account_id', $accountId);
        }
        $rows = $net
            ->groupBy('t.currency', DB::raw("to_char(t.booked_on, 'YYYY-MM')"))
            ->selectRaw("t.currency, to_char(t.booked_on, 'YYYY-MM') as m, (SUM(SUM(t.amount_minor)) OVER (PARTITION BY t.currency ORDER BY to_char(t.booked_on, 'YYYY-MM')))::bigint as running")
            ->orderBy('t.currency')
            ->orderBy('m')
            ->get();

        $running = [];
        foreach ($rows as $row) {
            $running[(string) $row->currency][(string) $row->m] = (int) $row->running;
        }

        $openings = $this->openings($viewer, $accountId);

        $labels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $labels[] = date('Y-m', (int) strtotime($month.'-01 -'.$i.' months'));
        }

        $out = [];
        foreach ($this->currencies($viewer, $accountId) as $currency) {
            $points = $running[$currency] ?? [];
            ksort($points);
            $series = [];
            foreach ($labels as $label) {
                $cumulative = 0;
                foreach ($points as $m => $value) {
                    if ($m <= $label) {
                        $cumulative = $value;
                    }
                }
                $series[] = ['month' => $label, 'balanceMinor' => (string) (($openings[$currency] ?? 0) + $cumulative)];
            }
            $out[$currency] = $series;
        }

        return $out;
    }

    /** @return array<string, int> opening + rows up to the date, per currency */
    private function balances(Viewer $viewer, string $asOf, ?string $accountId): array
    {
        $sums = Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->where('t.booked_on', '<=', $asOf);
        if ($accountId !== null) {
            $sums->where('t.account_id', $accountId);
        }
        $out = $this->openings($viewer, $accountId);
        foreach ($sums->groupBy('t.currency')->selectRaw('t.currency, SUM(t.amount_minor)::bigint as total')->get() as $row) {
            $out[(string) $row->currency] = ($out[(string) $row->currency] ?? 0) + (int) $row->total;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function openings(Viewer $viewer, ?string $accountId): array
    {
        $query = Visibility::apply(DB::table('finance_accounts as a'), $viewer, 'a.id');
        if ($accountId !== null) {
            $query->where('a.id', $accountId);
        }
        $out = [];
        foreach ($query->groupBy('a.currency')->selectRaw('a.currency, SUM(a.opening_balance_minor)::bigint as opening')->get() as $row) {
            $out[(string) $row->currency] = (int) $row->opening;
        }

        return $out;
    }

    /** @return list<string> visible currencies in AccountQuery::currencies order, or the selected account's currency */
    private function currencies(Viewer $viewer, ?string $accountId): array
    {
        $query = Visibility::apply(DB::table('finance_accounts'), $viewer, 'id');
        if ($accountId !== null) {
            $query->where('id', $accountId);
        }

        return $query->selectRaw('currency, count(*) as n')
            ->groupBy('currency')
            ->orderByDesc('n')
            ->orderBy('currency')
            ->pluck('currency')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    /** @return array{0: string, 1: string} */
    private function bounds(string $month): array
    {
        $start = $month.'-01';

        return [$start, date('Y-m-t', (int) strtotime($start))];
    }

    private function percent(int $amount, int $total): int
    {
        return $total <= 0 ? 0 : intdiv($amount * 100 + intdiv($total, 2), $total);
    }
}
