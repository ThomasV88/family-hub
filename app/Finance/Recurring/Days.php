<?php

namespace App\Finance\Recurring;

use DateTimeImmutable;
use DateTimeZone;

/** Integer day arithmetic on Y-m-d strings (UTC midnight timestamps, no floats). */
final class Days
{
    public static function number(string $date): int
    {
        return intdiv((new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /** Signed days from $from to $to. */
    public static function between(string $from, string $to): int
    {
        return self::number($to) - self::number($from);
    }
}
