<?php

namespace App\Tasks;

use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;
use InvalidArgumentException;

/** Turns the structured pattern posted by the client into a canonical rule. The client never sends an RRULE. */
final class TaskRecurrence
{
    /**
     * Monthly repeats (and custom months) always anchor on the due date's day; a posted monthDay is ignored.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException
     */
    public static function pattern(array $input, string $dueDate): RecurrencePattern
    {
        $day = (int) substr($dueDate, 8, 2);
        $kind = $input['kind'] ?? null;
        $usesDay = $kind === 'monthly_day' || ($kind === 'custom' && ($input['unit'] ?? null) === 'month');
        if ($usesDay) {
            $input['monthDay'] = $day;
        }

        return RecurrencePattern::fromArray($input);
    }

    /** @param  array<string, mixed>  $input */
    public static function rule(array $input, string $dueDate): string
    {
        return RuleBuilder::toRrule(self::pattern($input, $dueDate));
    }

    /** Pattern of a stored rule, or null when it is missing or no longer valid. */
    public static function stored(?string $rule): ?RecurrencePattern
    {
        if ($rule === null) {
            return null;
        }
        try {
            return RuleBuilder::fromRrule($rule);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
