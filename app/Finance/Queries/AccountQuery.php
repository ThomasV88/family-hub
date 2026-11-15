<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use App\Models\FinanceAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountQuery
{
    /** @return Collection<int, FinanceAccount> */
    public function visible(Viewer $viewer): Collection
    {
        return Visibility::apply(FinanceAccount::query(), $viewer, 'id')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();
    }

    public function findVisible(Viewer $viewer, string $id): ?FinanceAccount
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Visibility::apply(FinanceAccount::query()->where('id', $id), $viewer, 'id')->first();
    }

    /** @return list<string> */
    public function visibleIds(Viewer $viewer): array
    {
        return Visibility::apply(FinanceAccount::query(), $viewer, 'id')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Opening balance plus the signed sum of rows booked on or before the date, per visible account.
     *
     * @return array<string, int>
     */
    public function balances(Viewer $viewer, string $asOfDate): array
    {
        $rows = Visibility::apply(
            DB::table('finance_accounts as a')
                ->leftJoin('finance_transactions as t', fn ($join) => $join
                    ->on('t.account_id', '=', 'a.id')
                    ->where('t.booked_on', '<=', $asOfDate)),
            $viewer,
            'a.id'
        )
            ->groupBy('a.id', 'a.opening_balance_minor')
            ->selectRaw('a.id, (a.opening_balance_minor + COALESCE(SUM(t.amount_minor), 0))::bigint as balance')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->id] = (int) $row->balance;
        }

        return $out;
    }

    /**
     * Currencies of the visible accounts, most frequent first, then by code.
     *
     * @return list<string>
     */
    public function currencies(Viewer $viewer): array
    {
        return Visibility::apply(DB::table('finance_accounts'), $viewer, 'id')
            ->selectRaw('currency, count(*) as n')
            ->groupBy('currency')
            ->orderByDesc('n')
            ->orderBy('currency')
            ->pluck('currency')
            ->map(fn ($c) => (string) $c)
            ->all();
    }
}
