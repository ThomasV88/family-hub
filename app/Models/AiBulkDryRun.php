<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A stored dry-run result (counts only) that a bulk start must reference. Written by Query classes. */
class AiBulkDryRun extends Model
{
    use HasUuids;

    protected $table = 'ai_bulk_dry_runs';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'daily_cap' => 'integer',
            'eligible' => 'integer',
            'skipped_consent' => 'integer',
            'skipped_unsupported' => 'integer',
            'skipped_decided' => 'integer',
            'skipped_active' => 'integer',
            'created_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
