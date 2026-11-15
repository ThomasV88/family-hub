<?php

namespace Tests\Support;

use App\Security\SensitiveSession;

/** Test double: the sensitive idle gate and confirm window are always satisfied. */
class AlwaysFreshSensitiveSession extends SensitiveSession
{
    public function fresh(): bool
    {
        return true;
    }

    public function recentlyConfirmed(): bool
    {
        return true;
    }
}
