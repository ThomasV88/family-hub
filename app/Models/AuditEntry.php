<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Rows are inserted once. Enforcement is layered: the model refuses single-row update/delete
 * (bulk query-builder calls bypass these hooks), the runtime role fh_app has no UPDATE/DELETE/TRUNCATE
 * privilege, and database triggers refuse UPDATE, and DELETE/TRUNCATE outside audit:prune. A DB owner or
 * superuser can still change the table; this protects the history from the application, not from the DB owner.
 */
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
