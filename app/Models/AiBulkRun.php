<?php

namespace App\Models;

use Database\Factories\AiBulkRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One bulk re-generation run: ids, enums, counts and times only. Written by Query classes. */
class AiBulkRun extends Model
{
    /** @use HasFactory<AiBulkRunFactory> */
    use HasFactory, HasUuids;

    protected $table = 'ai_bulk_runs';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'daily_cap' => 'integer',
            'eligible_total' => 'integer',
            'skipped_count' => 'integer',
            'last_enqueued_at' => 'datetime',
            'blocked_since' => 'datetime',
            'created_at' => 'datetime',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
