<?php

namespace App\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RRule\RRule;

/**
 * php-rrule backed engine. The rule string is re-validated through the RuleBuilder allowlist, DTSTART is
 * a DateTime in the user's zone (so the library keeps local wall-clock across DST), and nothing here
 * adds fixed 24h spans to UTC timestamps.
 */
final class RRuleRecurrenceEngine implements RecurrenceEngine
{
    /** Hard iteration guard (about 136 years of a daily rule); the real horizon is MAX_YEARS from the first possible result. */
    private const MAX_ITERATIONS = 50000;

    private const MAX_YEARS = 10;

    public function next(
        string $rrule,
        CarbonImmutable $anchorLocal,
        RecurrenceMode $mode,
        ?CarbonImmutable $completedAtLocal,
        string $timezone,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        $pattern = RuleBuilder::fromRrule($rrule);
        $zone = new DateTimeZone($timezone);
        $nowLocal = $now->setTimezone($timezone);
        $anchor = $anchorLocal->setTimezone($timezone);
        $time = $anchor->format('H:i');

        if ($mode === RecurrenceMode::Completion) {
            if ($completedAtLocal === null) {
                throw new InvalidArgumentException('Completion mode needs the completion time.');
            }
            $completed = $completedAtLocal->setTimezone($timezone);
            $start = LocalTime::at($completed->format('Y-m-d'), $time, $timezone);
            $isMonthly = in_array($pattern->kind, ['monthly_day', 'monthly_last'], true)
                || ($pattern->kind === 'custom' && $pattern->unit === 'month');
            $completedDay = $completed->format('Y-m-d');
            $completedMonth = $completed->format('Y-m');

            // Occurrences on the completion day (and, for monthly rules, in the completion month) never count:
            // the next one is completion date + interval.
            $accept = fn (CarbonImmutable $o): bool => $isMonthly
                ? $o->format('Y-m') !== $completedMonth
                : $o->format('Y-m-d') > $completedDay;
        } else {
            $start = $anchor;
            $accept = fn (CarbonImmutable $o): bool => $o->greaterThan($anchor) && $o->greaterThan($nowLocal);
        }

        // The horizon counts from where a result can first appear, so a long-overdue series still continues.
        $horizonFrom = $mode === RecurrenceMode::Schedule && $nowLocal->greaterThan($start) ? $nowLocal : $start;
        $limit = $horizonFrom->addYears(self::MAX_YEARS);
        $iterator = new RRule($rrule, DateTimeImmutable::createFromInterface($start)->setTimezone($zone));

        $seen = 0;
        foreach ($iterator as $occurrence) {
            $local = CarbonImmutable::instance($occurrence)->setTimezone($timezone);
            if (++$seen > self::MAX_ITERATIONS || $local->greaterThan($limit)) {
                return null;
            }
            if ($accept($local)) {
                return $local;
            }
        }

        return null;
    }

    public function between(
        string $rrule,
        CarbonImmutable $anchorLocal,
        string $fromDate,
        string $toDate,
        string $timezone,
        int $cap = 62,
    ): array {
        RuleBuilder::fromRrule($rrule);
        $anchor = $anchorLocal->setTimezone($timezone);
        $time = $anchor->format('H:i');
        $anchorDate = $anchor->format('Y-m-d');
        $start = LocalTime::at($anchorDate, $time, $timezone);
        // The horizon counts from where a result can first appear (like next()), not from the anchor: a bill that
        // started twelve years ago still has due dates in today's window.
        $limitDate = CarbonImmutable::parse(max($fromDate, $anchorDate), $timezone)->addYears(self::MAX_YEARS)->format('Y-m-d');
        $last = min($toDate, $limitDate);

        $dates = [];
        if ($anchorDate >= $fromDate && $anchorDate <= $last) {
            $dates[$anchorDate] = true;
        }

        $iterator = new RRule($rrule, DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone($timezone)));
        $seen = 0;
        foreach ($iterator as $occurrence) {
            $date = CarbonImmutable::instance($occurrence)->setTimezone($timezone)->format('Y-m-d');
            if (++$seen > self::MAX_ITERATIONS || $date > $last) {
                break;
            }
            if ($date >= $fromDate) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return array_map(
            fn (string $d): CarbonImmutable => LocalTime::at($d, $time, $timezone),
            array_slice($dates, 0, max(0, $cap)),
        );
    }
}
