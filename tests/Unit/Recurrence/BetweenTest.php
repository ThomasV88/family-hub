<?php

use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;
use Tests\TestCase;

uses(TestCase::class);

const BETWEEN_TZ = 'Europe/Bratislava';

function betweenDates(array $pattern, string $anchor, string $from, string $to, int $cap = 62): array
{
    $rule = RuleBuilder::toRrule(RecurrencePattern::fromArray($pattern));

    return array_map(
        fn ($d) => $d->format('Y-m-d'),
        app(RecurrenceEngine::class)->between($rule, LocalTime::at($anchor, '12:00', BETWEEN_TZ), $from, $to, BETWEEN_TZ, $cap),
    );
}

it('clamps day 31 to month ends and honours leap years', function () {
    $p = ['kind' => 'monthly_day', 'monthDay' => 31];

    expect(betweenDates($p, '2027-01-31', '2027-01-01', '2027-04-30'))->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'])
        ->and(betweenDates($p, '2028-01-31', '2028-02-01', '2028-02-29'))->toBe(['2028-02-29']);
});

it('enumerates past windows independent of now', function () {
    expect(betweenDates(['kind' => 'monthly_day', 'monthDay' => 15], '2019-12-15', '2020-01-01', '2020-03-31'))
        ->toBe(['2020-01-15', '2020-02-15', '2020-03-15']);
});

it('handles windows around the anchor', function () {
    $p = ['kind' => 'monthly_day', 'monthDay' => 15];

    expect(betweenDates($p, '2027-05-15', '2027-01-01', '2027-04-30'))->toBe([])
        ->and(betweenDates($p, '2027-05-15', '2027-05-15', '2027-06-14'))->toBe(['2027-05-15']);
});

it('keeps local noon across DST switches', function () {
    $rule = RuleBuilder::toRrule(RecurrencePattern::fromArray(['kind' => 'weekly', 'weekdays' => ['SU']]));
    $out = app(RecurrenceEngine::class)->between($rule, LocalTime::at('2027-03-14', '12:00', BETWEEN_TZ), '2027-03-14', '2027-11-07', BETWEEN_TZ);

    expect($out)->toHaveCount(35);
    foreach ($out as $d) {
        expect($d->format('H:i'))->toBe('12:00');
    }
    expect(collect($out)->map->format('Y-m-d')->all())->toContain('2027-03-28', '2027-10-31');
});

it('respects the cap and counts daily items', function () {
    $d = ['kind' => 'daily'];

    expect(betweenDates($d, '2027-01-01', '2027-01-01', '2027-01-31'))->toHaveCount(31)
        ->and(betweenDates($d, '2027-01-01', '2027-01-01', '2027-04-30'))->toHaveCount(62);
});

it('rejects non-allowlisted rules', function () {
    app(RecurrenceEngine::class)->between('FREQ=YEARLY', LocalTime::at('2027-01-01', '12:00', BETWEEN_TZ), '2027-01-01', '2028-01-01', BETWEEN_TZ);
})->throws(InvalidArgumentException::class);
