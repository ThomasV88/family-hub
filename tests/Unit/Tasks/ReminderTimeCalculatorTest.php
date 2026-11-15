<?php

use App\Tasks\ReminderTimeCalculator;

const REMINDER_TZ = 'Europe/Bratislava';

function remindUtc(?string $date, ?string $time, ?int $before): ?string
{
    return (new ReminderTimeCalculator)->remindAt($date, $time, REMINDER_TZ, $before)?->utc()->format('Y-m-d H:i');
}

it('reminds a date-only task at 08:00 local', function () {
    expect(remindUtc('2027-05-10', null, 0))->toBe('2027-05-10 06:00')
        ->and(remindUtc('2027-01-10', null, 0))->toBe('2027-01-10 07:00');
});

it('applies offsets to a timed task', function () {
    expect(remindUtc('2027-05-10', '18:00', 0))->toBe('2027-05-10 16:00')
        ->and(remindUtc('2027-05-10', '18:00', 15))->toBe('2027-05-10 15:45')
        ->and(remindUtc('2027-05-10', '18:00', 60))->toBe('2027-05-10 15:00')
        ->and(remindUtc('2027-05-10', '18:00', 1440))->toBe('2027-05-09 16:00');
});

it('steps a day back on the wall clock across the 2027-03-28 change', function () {
    expect(remindUtc('2027-03-28', '09:00', 1440))->toBe('2027-03-27 08:00');
});

it('subtracts real minutes across the 2027-03-28 change', function () {
    expect(remindUtc('2027-03-28', '03:30', 60))->toBe('2027-03-28 00:30');
});

it('uses the previous day 08:00 for a date-only one-day-before reminder', function () {
    expect(remindUtc('2027-05-10', null, 1440))->toBe('2027-05-09 06:00')
        ->and(remindUtc('2027-03-29', null, 1440))->toBe('2027-03-28 06:00');
});

it('returns the UTC zone', function () {
    $r = (new ReminderTimeCalculator)->remindAt('2027-05-10', '18:00', REMINDER_TZ, 0);

    expect($r->getTimezone()->getName())->toBe('UTC');
});

it('returns null without a date or an offset', function () {
    expect(remindUtc(null, null, 0))->toBeNull()
        ->and(remindUtc(null, '10:00', 60))->toBeNull()
        ->and(remindUtc('2027-05-10', '10:00', null))->toBeNull();
});

it('rejects minute offsets on date-only tasks and unknown offsets', function (?string $time, int $offset) {
    remindUtc('2027-05-10', $time, $offset);
})->throws(InvalidArgumentException::class)->with([
    'date-only 15' => [null, 15],
    'date-only 60' => [null, 60],
    'offset 30' => ['10:00', 30],
    'offset -5' => ['10:00', -5],
    'offset 2880' => ['10:00', 2880],
]);
