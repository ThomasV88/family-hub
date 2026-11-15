<?php

namespace App\Models;

use Database\Factories\FinanceRecurringMatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Only App\Finance\Queries may read or change rows of this model. A match row exists only for a paid occurrence.
 */
class FinanceRecurringMatch extends Model
{
    /** @use HasFactory<FinanceRecurringMatchFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'finance_recurring_matches';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'expected_amount_minor' => 'integer',
        ];
    }
}
