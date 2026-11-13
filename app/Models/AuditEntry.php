<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. Rows are inserted once; the model refuses to update or delete them. */
class AuditEntry extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit entries are append-only.'));
    }
}
