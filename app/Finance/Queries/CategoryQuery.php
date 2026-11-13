<?php

namespace App\Finance\Queries;

use App\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Collection;
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
}
