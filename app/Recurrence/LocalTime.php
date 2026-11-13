<?php

namespace App\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Builds a local wall-clock instant. A nonexistent spring-forward time moves forward
 * (02:30 becomes 03:30); an ambiguous fall-back time takes the first instant.
 */
final class LocalTime
{
    public static function at(string $date, ?string $time, string $tz): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($time !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))) {
            throw new InvalidArgumentException('Invalid local date or time.');
        }

        $zone = new DateTimeZone($tz);
        $local = CarbonImmutable::instance(new DateTimeImmutable($date.' '.($time ?? '00:00'), $zone));

        // PHP resolves an ambiguous fall-back time to the second instant; the contract takes the first.
        $earlier = CarbonImmutable::createFromTimestamp($local->getTimestamp() - 3600, $zone);
        if ($earlier->format('Y-m-d H:i') === $local->format('Y-m-d H:i')) {
            return $earlier;
        }

        return $local;
    }
}
