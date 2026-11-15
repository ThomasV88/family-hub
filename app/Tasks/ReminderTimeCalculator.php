<?php

namespace App\Tasks;

use App\Recurrence\LocalTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * UTC reminder instant from a local due date/time. Date-only tasks remind at 08:00 local.
 * A "remind before" offset replaces the at-due reminder (one reminder at due minus offset).
 */
final class ReminderTimeCalculator
{
    private const DATE_ONLY_HOUR = '08:00';

    private const OFFSETS = [0, 15, 60, 1440];

    public function remindAt(?string $dueDate, ?string $dueTime, string $timezone, ?int $beforeMinutes): ?CarbonImmutable
    {
        if ($dueDate === null || $beforeMinutes === null) {
            return null;
        }
        if (! in_array($beforeMinutes, self::OFFSETS, true)) {
            throw new InvalidArgumentException('Unsupported reminder offset.');
        }
        if ($dueTime === null && in_array($beforeMinutes, [15, 60], true)) {
            throw new InvalidArgumentException('A minute offset needs a due time.');
        }

        $time = $dueTime ?? self::DATE_ONLY_HOUR;

        if ($beforeMinutes === 1440) {
            // Same local wall-clock one calendar day earlier (not 24 real hours, DST-safe).
            $previous = CarbonImmutable::createFromFormat('!Y-m-d', $dueDate, 'UTC');
            if ($previous === false || $previous->format('Y-m-d') !== $dueDate) {
                throw new InvalidArgumentException('Invalid due date.');
            }

            return LocalTime::at($previous->subDay()->format('Y-m-d'), $time, $timezone)->utc();
        }

        $base = LocalTime::at($dueDate, $time, $timezone)->utc();

        // Real minutes: subtracting from the UTC instant is exact across DST changes.
        return $beforeMinutes === 0 ? $base : $base->subMinutes($beforeMinutes);
    }
}
