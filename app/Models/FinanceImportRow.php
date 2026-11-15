<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One parsed statement row of a batch. Only App\Finance\Queries may read or change rows of this model.
 */
class FinanceImportRow extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'finance_import_rows';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'booked_on' => 'date:Y-m-d',
            'include' => 'boolean',
        ];
    }
}
