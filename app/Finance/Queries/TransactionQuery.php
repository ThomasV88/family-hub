<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use App\Models\FinanceTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionQuery
{
    /**
     * One page of the scoped, ordered list for a month.
     *
     * @param  array{q: string, type: string, category: string, source: string}  $filters
     * @return array{total: int, page: int, hasMore: bool, rows: list<object>}
     */
    public function page(Viewer $viewer, ?string $accountId, string $month, array $filters, int $page): array
    {
        $size = max(1, (int) config('finance.transactions.page_size', 50));
        $query = $this->base($viewer);

        $start = $month.'-01';
        $query->where('t.booked_on', '>=', $start)
            ->where('t.booked_on', '<=', date('Y-m-t', (int) strtotime($start)));

        if ($accountId !== null) {
            $query->where('t.account_id', $accountId);
        } else {
            // One row per fully visible transfer: its incoming leg is skipped.
            $query->whereNot(fn ($q) => $q
                ->where('t.kind', 'transfer')
                ->where('t.amount_minor', '>', 0)
                ->whereExists($this->counterpart($viewer)));
        }

        $this->applyFilters($query, $viewer, $filters);

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $size));
        $page = min(max(1, $page), $pages);

        $rows = $this->select($query, $viewer)
            ->orderByDesc('t.booked_on')
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get()
            ->all();

        return ['total' => $total, 'page' => $page, 'hasMore' => $page * $size < $total, 'rows' => $rows];
    }

    /** The row (with note) for the panel, or null when hidden or unknown. */
    public function panel(Viewer $viewer, string $id): ?object
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return $this->select($this->base($viewer)->where('t.id', $id), $viewer, true)->first();
    }

    public function findVisible(Viewer $viewer, string $id): ?FinanceTransaction
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Visibility::apply(FinanceTransaction::query()->where('id', $id), $viewer)->first();
    }

    /** The other leg of a transfer, only when its account is visible to the viewer; null when hidden or missing. */
    public function visibleCounterpart(Viewer $viewer, FinanceTransaction $leg): ?FinanceTransaction
    {
        if ($leg->transfer_id === null) {
            return null;
        }

        return Visibility::apply(
            FinanceTransaction::query()
                ->where('transfer_id', $leg->transfer_id)
                ->where('id', '<>', $leg->id),
            $viewer
        )->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVisible(Viewer $viewer, string $id, array $attributes): int
    {
        return Visibility::apply(DB::table('finance_transactions')->where('id', $id), $viewer)
            ->update($attributes + ['updated_at' => now()]);
    }

    /** @param  list<string>  $ids */
    public function deleteVisible(Viewer $viewer, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Visibility::apply(DB::table('finance_transactions')->whereIn('id', $ids), $viewer)->delete();
    }

    private function base(Viewer $viewer): Builder
    {
        return Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id');
    }

    /** Other leg of a transfer, restricted to accounts the viewer can see (so it never leaks a hidden counterpart). */
    private function counterpart(Viewer $viewer): Builder
    {
        return Visibility::apply(
            DB::table('finance_transactions as c')
                ->select('c.account_id')
                ->whereColumn('c.transfer_id', 't.transfer_id')
                ->whereColumn('c.id', '<>', 't.id'),
            $viewer,
            'c.account_id'
        );
    }

    private function select(Builder $query, Viewer $viewer, bool $withNote = false): Builder
    {
        $columns = ['t.id', 't.account_id', 't.currency', 't.kind', 't.amount_minor', 't.booked_on', 't.description',
            't.category_id', 't.source', 't.created_at'];
        if ($withNote) {
            $columns[] = 't.note';
        }

        $query->select($columns)
            ->selectSub($this->counterpart($viewer)->limit(1), 'counterpart_account_id')
            ->selectSub($this->matchColumn('r.name'), 'matched_recurring_name');
        if ($withNote) {
            // A match always lies on the transaction's own (visible) account, so these subqueries need no extra scope.
            $query->selectSub($this->matchColumn('r.id'), 'matched_recurring_id')
                ->selectSub($this->matchColumn('m.due_date'), 'matched_due_date');
        }

        return $query;
    }

    /** One column of the recurring match that pays the outer transaction (same account, hence visible). */
    private function matchColumn(string $column): Builder
    {
        return DB::table('finance_recurring_matches as m')
            ->join('finance_recurring as r', 'r.id', '=', 'm.recurring_id')
            ->whereColumn('m.transaction_id', 't.id')
            ->select($column)
            ->limit(1);
    }

    /** @param  array{q: string, type: string, category: string, source: string}  $filters */
    private function applyFilters(Builder $query, Viewer $viewer, array $filters): void
    {
        match ($filters['type']) {
            'transfer' => $query->where('t.kind', 'transfer')->whereExists($this->counterpart($viewer)),
            'income', 'expense' => $query->where(fn ($q) => $q
                ->where('t.kind', $filters['type'])
                ->orWhere(fn ($w) => $w
                    ->where('t.kind', 'transfer')
                    ->whereNotExists($this->counterpart($viewer))
                    ->where('t.amount_minor', $filters['type'] === 'income' ? '>' : '<', 0))),
            default => null,
        };

        if ($filters['category'] === 'none') {
            $query->whereNull('t.category_id');
        } elseif ($filters['category'] !== 'all') {
            $query->where('t.category_id', $filters['category']);
        }
        if ($filters['source'] !== 'all') {
            $query->where('t.source', $filters['source']);
        }
        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->whereRaw('t.description ilike ? escape ?', [$like, '\\']);
        }
    }
}
