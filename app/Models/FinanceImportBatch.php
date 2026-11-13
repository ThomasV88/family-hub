<?php

namespace App\Models;

use Database\Factories\FinanceImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A staged (draft), committed or undone import. Only App\Finance\Queries may read or change rows of this model.
 */
class FinanceImportBatch extends Model
{
    /** @use HasFactory<FinanceImportBatchFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_import_batches';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'profile_snapshot' => 'array',
            'committed_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
        ];
    }
}
