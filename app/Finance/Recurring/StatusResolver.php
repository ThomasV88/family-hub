<?php

namespace App\Finance\Recurring;

/** Derives the displayed bill status; nothing here is stored. */
final class StatusResolver
{
    public function resolve(Occurrence $o, ?MatchCandidate $matched, string $today): BillStatus
    {
        if ($matched === null) {
            return $o->dueDate >= $today ? BillStatus::Upcoming : BillStatus::Overdue;
        }

        $inTolerance = abs($this->difference($o, $matched)) <= $o->toleranceMinor;
        $inWindow = abs(Days::between($o->dueDate, $matched->bookedOn)) <= $o->windowDays;

        return $inTolerance && $inWindow ? BillStatus::Paid : BillStatus::PaidDifferently;
    }

    /** abs(actual) - expected, signed. */
    public function difference(Occurrence $o, MatchCandidate $matched): int
    {
        return abs($matched->amountMinor) - $o->expectedMinor;
    }
}
