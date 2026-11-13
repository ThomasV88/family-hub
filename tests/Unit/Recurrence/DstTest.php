<?php

use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrenceMode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

const DST_TZ = 'Europe/Bratislava';

function dstNext(string $rrule, string $date, string $time, string $now = '2026-01-01 00:00'): CarbonImmutable
{
    return app(RecurrenceEngine::class)->next(
        $rrule,
        LocalTime::at($date, $time, DST_TZ),
        RecurrenceMode::Schedule,
        null,
        DST_TZ,
        CarbonImmutable::parse($now, DST_TZ),
    );
}

it('keeps a daily 08:00 task at 08:00 local across the spring change', function () {
    $next = dstNext('FREQ=DAILY;INTERVAL=1', '2027-03-27', '08:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-03-28 08:00')
        ->and($next->utc()->format('Y-m-d H:i'))->toBe('2027-03-28 06:00');
});

it('keeps a daily 08:00 task at 08:00 local across the autumn change', function () {
    $next = dstNext('FREQ=DAILY;INTERVAL=1', '2027-10-30', '08:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-10-31 08:00')
        ->and($next->utc()->format('Y-m-d H:i'))->toBe('2027-10-31 07:00');
});

it('keeps a weekly Sunday 08:00 task at 08:00 local on 2027-03-28', function () {
    $next = dstNext('FREQ=WEEKLY;INTERVAL=1;BYDAY=SU', '2027-03-21', '08:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-03-28 08:00')
        ->and($next->utc()->format('Y-m-d H:i'))->toBe('2027-03-28 06:00');
});

it('moves the nonexistent 02:30 forward on 2027-03-28 and returns to 02:30 a week later', function () {
    $rule = 'FREQ=WEEKLY;INTERVAL=1;BYDAY=SU';

    expect(dstNext($rule, '2027-03-21', '02:30')->format('Y-m-d H:i'))->toBe('2027-03-28 03:30')
        ->and(dstNext($rule, '2027-03-21', '02:30', '2027-03-30 00:00')->format('Y-m-d H:i'))->toBe('2027-04-04 02:30');
});

it('pins LocalTime behaviour on nonexistent and ambiguous times', function () {
    $spring = LocalTime::at('2027-03-28', '02:30', DST_TZ);
    $autumn = LocalTime::at('2027-10-31', '02:30', DST_TZ);

    expect($spring->format('Y-m-d H:i'))->toBe('2027-03-28 03:30')
        ->and($spring->utc()->format('H:i'))->toBe('01:30')
        ->and($autumn->format('Y-m-d H:i'))->toBe('2027-10-31 02:30')
        ->and($autumn->utc()->format('H:i'))->toBe('00:30');
});

it('keeps wall-clock across the 2026 spring change (regression)', function () {
    $next = dstNext('FREQ=DAILY;INTERVAL=1', '2026-03-28', '08:00');

    expect($next->format('Y-m-d H:i'))->toBe('2026-03-29 08:00')
        ->and($next->utc()->format('Y-m-d H:i'))->toBe('2026-03-29 06:00');
});
