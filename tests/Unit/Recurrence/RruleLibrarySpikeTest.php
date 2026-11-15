<?php

use RRule\RRule;

const RRULE_SPIKE_NONEXISTENT = '2027-03-28 03:30';
const RRULE_SPIKE_TZ = 'Europe/Bratislava';

/**
 * @return list<string> local Y-m-d H:i strings of the first $count occurrences
 */
function rruleLocal(array $rule, string $dtstart, int $count): array
{
    $rrule = new RRule($rule + ['DTSTART' => new DateTimeImmutable($dtstart, new DateTimeZone(RRULE_SPIKE_TZ)), 'COUNT' => $count]);

    return array_map(fn (DateTimeInterface $d) => $d->format('Y-m-d H:i'), $rrule->getOccurrences());
}

function rruleUtc(array $rule, string $dtstart, int $count): array
{
    $rrule = new RRule($rule + ['DTSTART' => new DateTimeImmutable($dtstart, new DateTimeZone(RRULE_SPIKE_TZ)), 'COUNT' => $count]);

    return array_map(
        fn (DateTimeInterface $d) => DateTimeImmutable::createFromInterface($d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
        $rrule->getOccurrences(),
    );
}

const RRULE_SPIKE_CLAMP = [
    'FREQ' => 'MONTHLY',
    'BYMONTHDAY' => [28, 29, 30, 31],
    'BYSETPOS' => -1,
];

it('clamps a day-31 monthly rule to the last day via BYSETPOS=-1', function () {
    expect(rruleLocal(RRULE_SPIKE_CLAMP, '2027-01-31 08:00', 4))->toBe([
        '2027-01-31 08:00', '2027-02-28 08:00', '2027-03-31 08:00', '2027-04-30 08:00',
    ]);
});

it('clamps to Feb 29 in a leap year with BYSETPOS=-1', function () {
    expect(rruleLocal(RRULE_SPIKE_CLAMP, '2028-01-31 08:00', 2))->toBe(['2028-01-31 08:00', '2028-02-29 08:00']);
});

it('clamps a day-30 rule (BYMONTHDAY=28,29,30;BYSETPOS=-1)', function () {
    $out = rruleLocal(['FREQ' => 'MONTHLY', 'BYMONTHDAY' => [28, 29, 30], 'BYSETPOS' => -1], '2027-01-30 08:00', 3);
    expect($out)->toBe(['2027-01-30 08:00', '2027-02-28 08:00', '2027-03-30 08:00']);
});

it('clamps a day-29 rule across a non-leap and a leap February (BYSETPOS=-1)', function () {
    $nonLeap = rruleLocal(['FREQ' => 'MONTHLY', 'BYMONTHDAY' => [28, 29], 'BYSETPOS' => -1], '2027-01-29 08:00', 2);
    $leap = rruleLocal(['FREQ' => 'MONTHLY', 'BYMONTHDAY' => [28, 29], 'BYSETPOS' => -1], '2028-01-29 08:00', 2);
    expect($nonLeap)->toBe(['2027-01-29 08:00', '2027-02-28 08:00'])
        ->and($leap)->toBe(['2028-01-29 08:00', '2028-02-29 08:00']);
});

it('supports negative BYMONTHDAY=-1 as month end', function () {
    expect(rruleLocal(['FREQ' => 'MONTHLY', 'BYMONTHDAY' => -1], '2027-01-31 08:00', 4))->toBe([
        '2027-01-31 08:00', '2027-02-28 08:00', '2027-03-31 08:00', '2027-04-30 08:00',
    ]);
});

it('keeps the anchor day without drift for every-3-months clamp (BYSETPOS=-1)', function () {
    $rule = ['FREQ' => 'MONTHLY', 'INTERVAL' => 3] + RRULE_SPIKE_CLAMP;
    expect(rruleLocal($rule, '2027-01-31 08:00', 4))->toBe([
        '2027-01-31 08:00', '2027-04-30 08:00', '2027-07-31 08:00', '2027-10-31 08:00',
    ]);
});

it('clamps every-1-month after DTSTART (BYSETPOS=-1 interval 1)', function () {
    $rule = ['FREQ' => 'MONTHLY', 'INTERVAL' => 1] + RRULE_SPIKE_CLAMP;
    expect(array_slice(rruleLocal($rule, '2027-01-31 08:00', 4), 1))->toBe([
        '2027-02-28 08:00', '2027-03-31 08:00', '2027-04-30 08:00',
    ]);
});

it('observes plain FREQ=MONTHLY from the 31st skips short months (why 03-04 never emits it)', function () {
    // Observation only, not counted toward the ENGINE decision.
    expect(rruleLocal(['FREQ' => 'MONTHLY', 'INTERVAL' => 1], '2027-01-31 08:00', 3))->toBe([
        '2027-01-31 08:00', '2027-03-31 08:00', '2027-05-31 08:00',
    ]);
});

it('keeps local wall-clock across spring DST (2027-03-28)', function () {
    expect(rruleLocal(['FREQ' => 'DAILY'], '2027-03-27 08:00', 3))->toBe([
        '2027-03-27 08:00', '2027-03-28 08:00', '2027-03-29 08:00',
    ]);
    expect(rruleUtc(['FREQ' => 'DAILY'], '2027-03-27 08:00', 2))->toBe([
        '2027-03-27 07:00', '2027-03-28 06:00',
    ]);
});

it('keeps local wall-clock across autumn DST (2027-10-31)', function () {
    expect(rruleLocal(['FREQ' => 'DAILY'], '2027-10-30 08:00', 2))->toBe(['2027-10-30 08:00', '2027-10-31 08:00']);
    expect(rruleUtc(['FREQ' => 'DAILY'], '2027-10-30 08:00', 2))->toBe(['2027-10-30 06:00', '2027-10-31 07:00']);
});

it('pins the nonexistent 02:30 local time on 2027-03-28 as observed and returns to 02:30 next week', function () {
    $out = rruleLocal(['FREQ' => 'WEEKLY', 'BYDAY' => 'SU'], '2027-03-21 02:30', 3);
    expect($out[0])->toBe('2027-03-21 02:30')
        ->and($out[2])->toBe('2027-04-04 02:30');
    // Observed (pinned, library behaviour): nonexistent 02:30 is moved forward to 03:30 local.
    expect($out[1])->toBe(RRULE_SPIKE_NONEXISTENT);
});

it('expands a biweekly Monday/Wednesday set', function () {
    expect(rruleLocal(['FREQ' => 'WEEKLY', 'INTERVAL' => 2, 'BYDAY' => 'MO,WE'], '2027-01-04 08:00', 4))->toBe([
        '2027-01-04 08:00', '2027-01-06 08:00', '2027-01-18 08:00', '2027-01-20 08:00',
    ]);
});

it('returns occurrences in the DTSTART timezone', function () {
    $rrule = new RRule(['FREQ' => 'DAILY', 'COUNT' => 1, 'DTSTART' => new DateTimeImmutable('2027-01-04 08:00', new DateTimeZone(RRULE_SPIKE_TZ))]);
    expect($rrule->getOccurrences()[0]->getTimezone()->getName())->toBe(RRULE_SPIKE_TZ);
});
