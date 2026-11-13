<?php

namespace App\Recurrence;

use Carbon\CarbonImmutable;

interface RecurrenceEngine
{
    /**
     * Next scheduled local wall-clock datetime in $timezone, or null when none exists within the cap
     * (1000 occurrences or 10 years). $anchorLocal is the occurrence's scheduled local due.
     */
    public function next(
        string $rrule,
        CarbonImmutable $anchorLocal,
        RecurrenceMode $mode,
        ?CarbonImmutable $completedAtLocal,
        string $timezone,
        CarbonImmutable $now,
    ): ?CarbonImmutable;
}
