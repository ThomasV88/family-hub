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
    private const MAX_OCCURRENCES = 1000;

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
            $nowLocal = $now->setTimezone($timezone);
            $accept = fn (CarbonImmutable $o): bool => $o->greaterThan($anchor) && $o->greaterThan($nowLocal);
        }

        $limit = $start->addYears(self::MAX_YEARS);
        $iterator = new RRule($rrule, DateTimeImmutable::createFromInterface($start)->setTimezone($zone));

        $seen = 0;
        foreach ($iterator as $occurrence) {
            $local = CarbonImmutable::instance($occurrence)->setTimezone($timezone);
            if (++$seen > self::MAX_OCCURRENCES || $local->greaterThan($limit)) {
                return null;
            }
            if ($accept($local)) {
                return $local;
            }
        }

        return null;
    }
}
