<?php

namespace App\Models;

use Database\Factories\FinanceTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Only App\Finance\Queries may read or change rows of this model. New rows are created with new + forceFill + save.
 */
class FinanceTransaction extends Model
{
    /** @use HasFactory<FinanceTransactionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_transactions';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'booked_on' => 'date:Y-m-d',
            'edited_at' => 'immutable_datetime',
        ];
    }
}
