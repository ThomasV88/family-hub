<?php

namespace App\Models;

use Database\Factories\FinanceBudgetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a category's monthly limit in one currency, valid from effective_from (first day of a month) until
 * the next version. A NULL amount is a tombstone. Only App\Finance\Queries may read or change rows of this model.
 */
class FinanceBudget extends Model
{
    /** @use HasFactory<FinanceBudgetFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_budgets';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_from' => 'date:Y-m-d',
        ];
    }
}
