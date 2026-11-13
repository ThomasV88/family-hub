<?php

namespace App\Finance\Categories;

use App\Finance\Queries\CategoryQuery;
use App\Models\FinanceCategory;
use Illuminate\Support\Facades\DB;

/** Family category list changes. All row changes go through CategoryQuery. */
class CategoryWriter
{
    public function __construct(private readonly CategoryQuery $categories) {}

    public function create(string $kind, string $name): FinanceCategory
    {
        return $this->categories->create($kind, $name);
    }

    public function rename(FinanceCategory $category, string $name): void
    {
        $this->categories->rename((string) $category->id, $name);
    }

    /** Moves everything that uses the category to $target, then deletes it, in one transaction. */
    public function delete(FinanceCategory $category, FinanceCategory $target): void
    {
        DB::transaction(function () use ($category, $target) {
            $this->categories->reassign((string) $category->id, (string) $target->id);
            $this->categories->remove((string) $category->id);
        });
    }
}
