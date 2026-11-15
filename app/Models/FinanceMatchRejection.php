<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Only App\Finance\Queries may read or change rows of this model. A rejection stops the auto-matcher from
 * proposing the same transaction for the same occurrence again.
 */
class FinanceMatchRejection extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'finance_match_rejections';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
    }
}
