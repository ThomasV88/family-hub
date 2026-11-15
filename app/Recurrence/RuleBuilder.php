<?php

namespace App\Recurrence;

use InvalidArgumentException;

/**
 * Allowlisted mapping between UI patterns and RRULE strings (no DTSTART, COUNT, UNTIL or time parts).
 * Month-end clamping uses BYMONTHDAY=28..N;BYSETPOS=-1 so a day-31 rule lands on the last day of short months.
 */
final class RuleBuilder
{
    private const MAX_LENGTH = 255;

    public static function toRrule(RecurrencePattern $p): string
    {
        return match ($p->kind) {
            'daily' => 'FREQ=DAILY;INTERVAL=1',
            'weekly' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY='.implode(',', $p->weekdays),
            'monthly_day' => self::monthly(1, (int) $p->monthDay),
            'monthly_last' => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1',
            'custom' => match ($p->unit) {
                'day' => 'FREQ=DAILY;INTERVAL='.$p->interval,
                'week' => 'FREQ=WEEKLY;INTERVAL='.$p->interval,
                default => self::monthly($p->interval, (int) $p->monthDay),
            },
        };
    }

    public static function fromRrule(string $rrule): RecurrencePattern
    {
        if ($rrule === '' || strlen($rrule) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid recurrence rule length.');
        }

        $parts = [];
        foreach (explode(';', $rrule) as $segment) {
            $pair = explode('=', $segment);
            if (count($pair) !== 2 || ! in_array($pair[0], ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS'], true) || isset($parts[$pair[0]])) {
                throw new InvalidArgumentException('Recurrence rule is outside the allowed shapes.');
            }
            $parts[$pair[0]] = $pair[1];
        }

        $interval = $parts['INTERVAL'] ?? '';
        if (! preg_match('/^[1-9][0-9]?$/', $interval)) {
            throw new InvalidArgumentException('Invalid interval.');
        }
        $interval = (int) $interval;

        $pattern = match ($parts['FREQ'] ?? null) {
            'DAILY' => RecurrencePattern::fromArray(['kind' => 'custom', 'interval' => $interval, 'unit' => 'day']),
            'WEEKLY' => isset($parts['BYDAY'])
                ? RecurrencePattern::fromArray(['kind' => 'weekly', 'interval' => $interval, 'weekdays' => explode(',', $parts['BYDAY'])])
                : RecurrencePattern::fromArray(['kind' => 'custom', 'interval' => $interval, 'unit' => 'week']),
            'MONTHLY' => self::monthlyPattern($parts, $interval),
            default => throw new InvalidArgumentException('Unsupported frequency.'),
        };

        // Exact inverse: anything not byte-identical to what toRrule() produces is rejected.
        if (self::toRrule($pattern) !== $rrule) {
            throw new InvalidArgumentException('Recurrence rule is not in canonical form.');
        }

        return $pattern;
    }

    /**
     * @param  array<string, string>  $parts
     */
    private static function monthlyPattern(array $parts, int $interval): RecurrencePattern
    {
        $byMonthDay = $parts['BYMONTHDAY'] ?? null;
        if ($byMonthDay === null) {
            throw new InvalidArgumentException('A monthly rule needs BYMONTHDAY.');
        }
        if ($byMonthDay === '-1') {
            return RecurrencePattern::fromArray(['kind' => 'monthly_last']);
        }
        $days = explode(',', $byMonthDay);
        foreach ($days as $day) {
            if (! preg_match('/^[1-9][0-9]?$/', $day)) {
                throw new InvalidArgumentException('Invalid BYMONTHDAY.');
            }
        }

        return RecurrencePattern::fromArray([
            'kind' => 'custom',
            'interval' => $interval,
            'unit' => 'month',
            'monthDay' => max(array_map('intval', $days)),
        ]);
    }

    private static function monthly(int $interval, int $monthDay): string
    {
        $base = 'FREQ=MONTHLY;INTERVAL='.$interval.';BYMONTHDAY=';
        if ($monthDay <= 28) {
            return $base.$monthDay;
        }

        return $base.implode(',', range(28, $monthDay)).';BYSETPOS=-1';
    }
}
