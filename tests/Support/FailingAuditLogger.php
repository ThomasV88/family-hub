<?php

namespace Tests\Support;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use RuntimeException;

/** Test double: every audit write fails, to prove state changes are not left behind a 500. */
class FailingAuditLogger extends AuditLogger
{
    public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void
    {
        throw new RuntimeException('audit write failed');
    }
}
