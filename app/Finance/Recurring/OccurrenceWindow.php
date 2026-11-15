<?php

namespace App\Finance\Recurring;

use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;

/**
 * Due dates of one recurring item inside a window. Schedule semantics only: the first due date anchors the rule at
 * 12:00 family time, so month-end clamping and DST never move a date.
 */
class OccurrenceWindow
{
    public function __construct(private readonly RecurrenceEngine $engine) {}

    /**
     * @param  object{recurrence_rule: string, starts_on: mixed, ends_on: mixed}  $recurring
     * @return list<string> Y-m-d, ascending
     */
    public function dueDates(object $recurring, string $from, string $to): array
    {
        $timezone = (string) config('family.timezone');
        $startsOn = substr((string) $recurring->starts_on, 0, 10);
        $endsOn = $recurring->ends_on === null ? null : substr((string) $recurring->ends_on, 0, 10);

        $lo = max($from, $startsOn);
        $hi = $endsOn !== null ? min($to, $endsOn) : $to;
        if ($lo > $hi) {
            return [];
        }

        $anchor = LocalTime::at($startsOn, '12:00', $timezone);
        $dates = [];
        foreach ($this->engine->between((string) $recurring->recurrence_rule, $anchor, $lo, $hi, $timezone) as $instant) {
            $dates[] = $instant->format('Y-m-d');
        }

        return $dates;
    }
}
