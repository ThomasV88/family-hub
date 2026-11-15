<?php

namespace App\Models;

use Database\Factories\FinanceRecurringFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Only App\Finance\Queries may read or change rows of this model. New rows are created with new + forceFill + save.
 */
class FinanceRecurring extends Model
{
    /** @use HasFactory<FinanceRecurringFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_recurring';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expected_amount_minor' => 'integer',
            'tolerance_minor' => 'integer',
            'window_days' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'schedule_effective_from' => 'date:Y-m-d',
        ];
    }
}
