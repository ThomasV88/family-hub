<?php

namespace App\Finance\Queries;

use App\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Family-wide, non-sensitive category list: no viewer scope by design. */
class CategoryQuery
{
    /** @return Collection<int, FinanceCategory> */
    public function all(): Collection
    {
        return FinanceCategory::query()
            ->orderBy('kind')
            ->orderByRaw('lower(coalesce(name, default_key))')
            ->orderBy('id')
            ->get();
    }

    public function find(string $id): ?FinanceCategory
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return FinanceCategory::query()->whereKey($id)->first();
    }

    /** True when a custom category of this kind already carries the name (case-insensitive), $exceptId aside. */
    public function nameTaken(string $kind, string $name, ?string $exceptId = null): bool
    {
        $query = FinanceCategory::query()->where('kind', $kind)->whereRaw('lower(name) = ?', [mb_strtolower($name)]);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    public function create(string $kind, string $name): FinanceCategory
    {
        $category = new FinanceCategory;
        $category->forceFill(['kind' => $kind, 'default_key' => null, 'name' => $name])->save();

        return $category;
    }

    /** Sets the stored name; a default category keeps its default_key. */
    public function rename(string $id, string $name): void
    {
        FinanceCategory::query()->whereKey($id)->update(['name' => $name, 'updated_at' => now()]);
    }

    public function remove(string $id): void
    {
        FinanceCategory::query()->whereKey($id)->delete();
    }

    /**
     * Unscoped write: moves everything that uses category $from to category $to, on every account. Categories are a
     * family-wide list and deleting one requires a move target (UI-SPEC "7. Categories"), so this deliberately has
     * no membership predicate and returns nothing (no counts). Consequences that are intended:
     * (a) it can delete a from-budget row in a currency hidden from the actor, when the target already has a budget
     * for that (currency, effective_from): the target's budget wins and the actor learns nothing about it;
     * (b) it must NOT touch updated_at or edited_at on rewritten rows, and must change nothing but category_id on
     * hidden transactions and recurring rows: reassigning a category is not a user edit, so a later import undo
     * still deletes untouched imported rows. Hence raw UPDATE statements that set category_id only.
     * Draft import rows follow the same rule so an open import keeps its categories.
     */
    public function reassign(string $from, string $to): void
    {
        DB::delete(
            'delete from finance_budgets as f where f.category_id = ? and exists ('
            .'select 1 from finance_budgets as t where t.category_id = ? and t.currency = f.currency and t.effective_from = f.effective_from)',
            [$from, $to],
        );
        foreach (['finance_budgets', 'finance_transactions', 'finance_recurring', 'finance_import_rows'] as $table) {
            DB::update('update '.$table.' set category_id = ? where category_id = ?', [$to, $from]);
        }
    }
}
