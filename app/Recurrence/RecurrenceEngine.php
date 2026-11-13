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

    /**
     * Occurrences (Schedule semantics, independent of the real clock) whose local date lies in
     * [$fromDate, $toDate] (Y-m-d, inclusive), ascending, at most $cap. The anchor date is always the first
     * occurrence. Returned instants are local wall-clock at the anchor's time of day.
     *
     * @return list<CarbonImmutable>
     */
    public function between(
        string $rrule,
        CarbonImmutable $anchorLocal,
        string $fromDate,
        string $toDate,
        string $timezone,
        int $cap = 62,
    ): array;
}
