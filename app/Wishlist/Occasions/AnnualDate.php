<?php

namespace App\Wishlist\Occasions;

use Carbon\CarbonImmutable;

/** Pure date maths for yearly occasions. A Feb 29 date is observed on Feb 28 in non-leap years. */
final class AnnualDate
{
    public static function next(int $month, int $day, CarbonImmutable $from): CarbonImmutable
    {
        $today = $from->startOfDay();
        for ($year = $today->year; $year <= $today->year + 8; $year++) {
            $d = $day;
            if (! checkdate($month, $d, $year)) {
                if ($month === 2 && $d === 29) {
                    $d = 28;
                } else {
                    throw new \InvalidArgumentException("Invalid date {$month}-{$day}");
                }
            }
            $candidate = $today->setDate($year, $month, $d);
            if ($candidate->greaterThanOrEqualTo($today)) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException("Invalid date {$month}-{$day}");
    }

    public static function daysUntil(CarbonImmutable $date, CarbonImmutable $today): int
    {
        $zone = $today->getTimezone();
        $a = CarbonImmutable::parse($today->format('Y-m-d'), 'UTC');
        $b = CarbonImmutable::parse($date->setTimezone($zone)->format('Y-m-d'), 'UTC');

        return (int) $a->diffInDays($b, false);
    }
}
