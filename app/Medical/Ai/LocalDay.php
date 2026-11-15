<?php

namespace App\Medical\Ai;

use Carbon\CarbonImmutable;

/**
 * The family's local day (config ai.local_timezone). One place computes its start, so the Copilot ceiling, the bulk tick
 * and the admin panel count the same window.
 *
 * Bounds are bound to timestamptz columns as UTC instants with an explicit offset: Laravel formats a DateTimeInterface
 * binding as a naive wall-clock string in the object's own zone, so a zoned Carbon would be read in the session zone.
 */
final class LocalDay
{
    public static function zone(): string
    {
        return (string) config('ai.local_timezone', 'UTC');
    }

    /** Start of the local day that contains $now, as a UTC instant. */
    public static function startUtc(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::zone())->startOfDay()->utc();
    }

    /** Start of the next local day (calendar arithmetic, so a 23 or 25 hour day is handled), as a UTC instant. */
    public static function nextStartUtc(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::zone())->addDay()->startOfDay()->utc();
    }

    /** The binding for a timestamptz comparison: an ISO 8601 UTC instant. */
    public static function startBinding(?CarbonImmutable $now = null): string
    {
        return self::startUtc($now)->toIso8601String();
    }

    /** The local calendar date, for per-day counters. */
    public static function date(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::zone())->format('Y-m-d');
    }
}
