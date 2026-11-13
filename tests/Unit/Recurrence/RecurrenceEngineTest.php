<?php

use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrenceMode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

const ENGINE_TZ = 'Europe/Bratislava';

function engineNext(string $rrule, string $anchor, RecurrenceMode $mode, ?string $completed, string $now): ?CarbonImmutable
{
    $a = CarbonImmutable::parse($anchor, ENGINE_TZ);

    return app(RecurrenceEngine::class)->next(
        $rrule,
        $a,
        $mode,
        $completed === null ? null : CarbonImmutable::parse($completed, ENGINE_TZ),
        ENGINE_TZ,
        CarbonImmutable::parse($now, ENGINE_TZ),
    );
}

it('is bound in the container', function () {
    expect(app(RecurrenceEngine::class))->toBeInstanceOf(RecurrenceEngine::class);
});

it('returns the next daily occurrence after the anchor', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=1', '2027-05-10 08:00', RecurrenceMode::Schedule, null, '2027-05-09 00:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-05-11 08:00');
});

it('skips past now after a late schedule completion (no pile-up)', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=1', '2027-05-10 08:00', RecurrenceMode::Schedule, '2027-05-13 12:00', '2027-05-13 12:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-05-14 08:00');
});

it('expands weekly MO,WE in schedule mode', function () {
    $rule = 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE';

    expect(engineNext($rule, '2027-05-10 08:00', RecurrenceMode::Schedule, null, '2027-05-01 00:00')->format('Y-m-d'))->toBe('2027-05-12')
        ->and(engineNext($rule, '2027-05-12 08:00', RecurrenceMode::Schedule, null, '2027-05-01 00:00')->format('Y-m-d'))->toBe('2027-05-17');
});

it('counts from the completion day in completion mode at the anchor time', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=3', '2027-05-10 08:00', RecurrenceMode::Completion, '2027-05-12 21:15', '2027-05-12 21:15');

    expect($next->format('Y-m-d H:i'))->toBe('2027-05-15 08:00');
});

it('never repeats on the completion day when completed before the due time', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=1', '2027-05-10 08:00', RecurrenceMode::Completion, '2027-05-10 07:00', '2027-05-10 07:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-05-11 08:00');
});

it('keeps BYDAY in completion mode', function () {
    // completed Monday: next listed weekday is the Wednesday of the same week
    $next = engineNext('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE', '2027-05-03 08:00', RecurrenceMode::Completion, '2027-05-10 09:00', '2027-05-10 09:00');

    expect($next->format('Y-m-d'))->toBe('2027-05-12');
});

it('uses the next month for monthly_day from completion', function () {
    $rule = 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15';

    expect(engineNext($rule, '2027-05-15 08:00', RecurrenceMode::Completion, '2027-05-20 10:00', '2027-05-20 10:00')->format('Y-m-d'))->toBe('2027-06-15');
});

it('returns date-only (00:00) results for date-only anchors', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=1', '2027-05-10 00:00', RecurrenceMode::Schedule, null, '2027-05-01 00:00');

    expect($next->format('Y-m-d H:i'))->toBe('2027-05-11 00:00');
});

it('returns null beyond the iteration cap', function () {
    // more than 1000 daily occurrences overdue
    expect(engineNext('FREQ=DAILY;INTERVAL=1', '2027-01-01 08:00', RecurrenceMode::Schedule, null, '2031-01-01 00:00'))->toBeNull()
        // more than 10 years overdue with a sparse rule
        ->and(engineNext('FREQ=MONTHLY;INTERVAL=99;BYMONTHDAY=1', '2027-01-01 08:00', RecurrenceMode::Schedule, null, '2040-01-01 00:00'))->toBeNull();
});

it('returns the result in the requested timezone', function () {
    $next = engineNext('FREQ=DAILY;INTERVAL=1', '2027-05-10 08:00', RecurrenceMode::Schedule, null, '2027-05-01 00:00');

    expect($next->getTimezone()->getName())->toBe(ENGINE_TZ);
});

it('rejects rules outside the allowlist', function () {
    engineNext('FREQ=YEARLY;INTERVAL=1', '2027-05-10 08:00', RecurrenceMode::Schedule, null, '2027-05-01 00:00');
})->throws(InvalidArgumentException::class);

it('requires a completion time in completion mode', function () {
    app(RecurrenceEngine::class)->next('FREQ=DAILY;INTERVAL=1', LocalTime::at('2027-05-10', '08:00', ENGINE_TZ), RecurrenceMode::Completion, null, ENGINE_TZ, CarbonImmutable::now());
})->throws(InvalidArgumentException::class);
