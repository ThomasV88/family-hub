<?php

use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;

dataset('rule_patterns', [
    'daily' => [['kind' => 'daily'], 'FREQ=DAILY;INTERVAL=1'],
    'weekly MO,WE' => [['kind' => 'weekly', 'weekdays' => ['MO', 'WE']], 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE'],
    'monthly 15' => [['kind' => 'monthly_day', 'monthDay' => 15], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15'],
    'monthly 28' => [['kind' => 'monthly_day', 'monthDay' => 28], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28'],
    'monthly 29' => [['kind' => 'monthly_day', 'monthDay' => 29], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29;BYSETPOS=-1'],
    'monthly 30' => [['kind' => 'monthly_day', 'monthDay' => 30], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30;BYSETPOS=-1'],
    'monthly 31' => [['kind' => 'monthly_day', 'monthDay' => 31], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1'],
    'monthly last' => [['kind' => 'monthly_last'], 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1'],
    'custom 3 days' => [['kind' => 'custom', 'interval' => 3, 'unit' => 'day'], 'FREQ=DAILY;INTERVAL=3'],
    'custom 2 weeks' => [['kind' => 'custom', 'interval' => 2, 'unit' => 'week'], 'FREQ=WEEKLY;INTERVAL=2'],
    'custom 1 week' => [['kind' => 'custom', 'interval' => 1, 'unit' => 'week'], 'FREQ=WEEKLY;INTERVAL=1'],
    'custom 6 months 15' => [['kind' => 'custom', 'interval' => 6, 'unit' => 'month', 'monthDay' => 15], 'FREQ=MONTHLY;INTERVAL=6;BYMONTHDAY=15'],
    'custom 3 months 31' => [['kind' => 'custom', 'interval' => 3, 'unit' => 'month', 'monthDay' => 31], 'FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=28,29,30,31;BYSETPOS=-1'],
]);

it('builds the exact rrule string and round-trips', function (array $input, string $rrule) {
    $pattern = RecurrencePattern::fromArray($input);

    expect(RuleBuilder::toRrule($pattern))->toBe($rrule)
        ->and(RuleBuilder::fromRrule($rrule))->toEqual($pattern)
        ->and(RecurrencePattern::fromArray($pattern->toArray()))->toEqual($pattern);
})->with('rule_patterns');

it('canonicalizes custom every-1 patterns', function () {
    $month = RecurrencePattern::fromArray(['kind' => 'custom', 'interval' => 1, 'unit' => 'month', 'monthDay' => 31]);
    $monthly = RecurrencePattern::fromArray(['kind' => 'monthly_day', 'monthDay' => 31]);

    expect($month)->toEqual($monthly)
        ->and(RuleBuilder::toRrule($month))->toBe('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1')
        ->and(RecurrencePattern::fromArray(['kind' => 'custom', 'interval' => 1, 'unit' => 'day']))
        ->toEqual(RecurrencePattern::fromArray(['kind' => 'daily']));
});

it('orders and de-duplicates weekdays', function () {
    $p = RecurrencePattern::fromArray(['kind' => 'weekly', 'weekdays' => ['WE', 'MO', 'WE']]);

    expect($p->weekdays)->toBe(['MO', 'WE']);
});

it('rejects invalid pattern input', function (array $input) {
    RecurrencePattern::fromArray($input);
})->throws(InvalidArgumentException::class)->with([
    'unknown kind' => [['kind' => 'yearly']],
    'no kind' => [[]],
    'interval 0' => [['kind' => 'custom', 'interval' => 0, 'unit' => 'day']],
    'interval 100' => [['kind' => 'custom', 'interval' => 100, 'unit' => 'day']],
    'interval string' => [['kind' => 'custom', 'interval' => '2', 'unit' => 'day']],
    'interval on daily' => [['kind' => 'daily', 'interval' => 2]],
    'weekly no weekdays' => [['kind' => 'weekly', 'weekdays' => []]],
    'weekly missing weekdays' => [['kind' => 'weekly']],
    'weekly XX' => [['kind' => 'weekly', 'weekdays' => ['XX']]],
    'monthDay 0' => [['kind' => 'monthly_day', 'monthDay' => 0]],
    'monthDay 32' => [['kind' => 'monthly_day', 'monthDay' => 32]],
    'monthly_day without monthDay' => [['kind' => 'monthly_day']],
    'custom without unit' => [['kind' => 'custom', 'interval' => 2]],
    'custom month without monthDay' => [['kind' => 'custom', 'interval' => 2, 'unit' => 'month']],
    'monthDay on daily' => [['kind' => 'daily', 'monthDay' => 5]],
    'monthDay on weekly' => [['kind' => 'weekly', 'weekdays' => ['MO'], 'monthDay' => 5]],
    'monthDay on monthly_last' => [['kind' => 'monthly_last', 'monthDay' => 5]],
    'monthDay on custom day' => [['kind' => 'custom', 'interval' => 2, 'unit' => 'day', 'monthDay' => 5]],
    'monthDay on custom week' => [['kind' => 'custom', 'interval' => 2, 'unit' => 'week', 'monthDay' => 5]],
    'unit on daily' => [['kind' => 'daily', 'unit' => 'day']],
    'extra key' => [['kind' => 'daily', 'rrule' => 'FREQ=DAILY']],
]);

it('rejects rrule strings outside the allowlist', function (string $rrule) {
    RuleBuilder::fromRrule($rrule);
})->throws(InvalidArgumentException::class)->with([
    'monthly without BYMONTHDAY' => ['FREQ=MONTHLY;INTERVAL=3'],
    'count' => ['FREQ=DAILY;INTERVAL=1;COUNT=5'],
    'until' => ['FREQ=DAILY;INTERVAL=1;UNTIL=20300101T000000Z'],
    'byhour' => ['FREQ=DAILY;INTERVAL=1;BYHOUR=8'],
    'yearly' => ['FREQ=YEARLY;INTERVAL=1'],
    'hourly' => ['FREQ=HOURLY;INTERVAL=1'],
    'dtstart' => ['DTSTART=20270101T080000;FREQ=DAILY;INTERVAL=1'],
    'too long' => [str_repeat('A', 256)],
    'empty' => [''],
    'lowercase' => ['freq=daily;interval=1'],
    'reordered' => ['INTERVAL=1;FREQ=DAILY'],
    'no interval' => ['FREQ=DAILY'],
    'interval 0' => ['FREQ=DAILY;INTERVAL=0'],
    'interval 100' => ['FREQ=DAILY;INTERVAL=100'],
    'interval leading zero' => ['FREQ=DAILY;INTERVAL=03'],
    'weekly with interval 2 and BYDAY' => ['FREQ=WEEKLY;INTERVAL=2;BYDAY=MO'],
    'last with interval' => ['FREQ=MONTHLY;INTERVAL=2;BYMONTHDAY=-1'],
    'negative monthday other' => ['FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-2'],
    'duplicate key' => ['FREQ=DAILY;FREQ=DAILY;INTERVAL=1'],
    'unsorted weekdays' => ['FREQ=WEEKLY;INTERVAL=1;BYDAY=WE,MO'],
    'setpos without clamp set' => ['FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15;BYSETPOS=-1'],
]);
