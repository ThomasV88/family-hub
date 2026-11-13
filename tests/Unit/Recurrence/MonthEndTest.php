<?php

use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrenceMode;
use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class);

const MONTHEND_TZ = 'Europe/Bratislava';

function monthEndRule(array $pattern): string
{
    return RuleBuilder::toRrule(RecurrencePattern::fromArray($pattern));
}

/** Chains next() $steps times, each result becoming the next anchor, and returns the local dates. */
function monthEndChain(string $rrule, string $start, int $steps): array
{
    $engine = app(RecurrenceEngine::class);
    $now = CarbonImmutable::parse('2026-01-01 00:00', MONTHEND_TZ);
    $anchor = LocalTime::at($start, '08:00', MONTHEND_TZ);
    $out = [];
    for ($i = 0; $i < $steps; $i++) {
        $anchor = $engine->next($rrule, $anchor, RecurrenceMode::Schedule, null, MONTHEND_TZ, $now);
        $out[] = $anchor->format('Y-m-d');
    }

    return $out;
}

it('clamps a monthly day-31 task to month ends without drift', function () {
    $rule = monthEndRule(['kind' => 'monthly_day', 'monthDay' => 31]);

    expect(monthEndChain($rule, '2027-01-31', 3))->toBe(['2027-02-28', '2027-03-31', '2027-04-30'])
        ->and(monthEndChain($rule, '2028-01-31', 1))->toBe(['2028-02-29']);
});

it('clamps day 30 and day 29', function () {
    expect(monthEndChain(monthEndRule(['kind' => 'monthly_day', 'monthDay' => 30]), '2027-01-30', 2))->toBe(['2027-02-28', '2027-03-30'])
        ->and(monthEndChain(monthEndRule(['kind' => 'monthly_day', 'monthDay' => 29]), '2027-01-29', 1))->toBe(['2027-02-28'])
        ->and(monthEndChain(monthEndRule(['kind' => 'monthly_day', 'monthDay' => 29]), '2028-01-29', 1))->toBe(['2028-02-29']);
});

it('handles monthly_last', function () {
    expect(monthEndChain(monthEndRule(['kind' => 'monthly_last']), '2027-01-31', 3))->toBe(['2027-02-28', '2027-03-31', '2027-04-30']);
});

it('handles custom every N months anchored on day 31 without drift', function () {
    $every = fn (int $n) => monthEndRule(['kind' => 'custom', 'interval' => $n, 'unit' => 'month', 'monthDay' => 31]);

    expect(monthEndChain($every(2), '2027-01-31', 3))->toBe(['2027-03-31', '2027-05-31', '2027-07-31'])
        ->and(monthEndChain($every(1), '2027-01-31', 3))->toBe(['2027-02-28', '2027-03-31', '2027-04-30'])
        ->and(monthEndChain($every(3), '2027-01-31', 2))->toBe(['2027-04-30', '2027-07-31']);
});

it('counts custom months from the completion month in completion mode', function () {
    $engine = app(RecurrenceEngine::class);
    $now = CarbonImmutable::parse('2027-06-01 00:00', MONTHEND_TZ);
    $due = LocalTime::at('2027-01-31', '08:00', MONTHEND_TZ);
    $one = monthEndRule(['kind' => 'custom', 'interval' => 1, 'unit' => 'month', 'monthDay' => 31]);
    $three = monthEndRule(['kind' => 'custom', 'interval' => 3, 'unit' => 'month', 'monthDay' => 31]);

    $a = $engine->next($one, $due, RecurrenceMode::Completion, LocalTime::at('2027-01-31', '09:00', MONTHEND_TZ), MONTHEND_TZ, $now);
    $b = $engine->next($three, $due, RecurrenceMode::Completion, LocalTime::at('2027-05-10', '09:00', MONTHEND_TZ), MONTHEND_TZ, $now);

    expect($a->format('Y-m-d H:i'))->toBe('2027-02-28 08:00')
        ->and($b->format('Y-m-d H:i'))->toBe('2027-08-31 08:00');
});
