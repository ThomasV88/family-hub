<?php

use App\Medical\Ai\LocalDay;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['ai.local_timezone' => 'Europe/Bratislava']);
});

it('starts the local day at the right UTC instant around local midnight', function (string $now, string $startUtc) {
    expect(LocalDay::startUtc(CarbonImmutable::parse($now, 'UTC'))->format('Y-m-d H:i:s'))->toBe($startUtc)
        ->and(LocalDay::startBinding(CarbonImmutable::parse($now, 'UTC')))->toBe(CarbonImmutable::parse($startUtc, 'UTC')->toIso8601String());
})->with([
    // Winter (UTC+1): 22:59 UTC is 23:59 local on the 10th, 23:00 UTC is 00:00 local on the 11th.
    'just before local midnight' => ['2026-01-10 22:59:59', '2026-01-09 23:00:00'],
    'at local midnight' => ['2026-01-10 23:00:00', '2026-01-10 23:00:00'],
    // Summer (UTC+2).
    'summer before midnight' => ['2026-07-10 21:59:59', '2026-07-09 22:00:00'],
    'summer at midnight' => ['2026-07-10 22:00:00', '2026-07-10 22:00:00'],
    // Spring forward on 2026-03-29: the day starts at +01:00 and lasts 23 hours.
    'spring-forward day, early' => ['2026-03-29 00:30:00', '2026-03-28 23:00:00'],
    'spring-forward day, evening' => ['2026-03-29 21:30:00', '2026-03-28 23:00:00'],
    'day after spring-forward' => ['2026-03-29 22:00:00', '2026-03-29 22:00:00'],
    // Fall back on 2026-10-25: the day starts at +02:00 and lasts 25 hours.
    'fall-back day, late' => ['2026-10-25 22:30:00', '2026-10-24 22:00:00'],
    'day after fall-back' => ['2026-10-25 23:00:00', '2026-10-25 23:00:00'],
]);

it('puts the next local day start across a 23 and a 25 hour day', function (string $now, string $nextUtc) {
    expect(LocalDay::nextStartUtc(CarbonImmutable::parse($now, 'UTC'))->format('Y-m-d H:i:s'))->toBe($nextUtc);
})->with([
    'spring-forward day' => ['2026-03-29 10:00:00', '2026-03-29 22:00:00'],
    'fall-back day' => ['2026-10-25 10:00:00', '2026-10-25 23:00:00'],
    'ordinary day' => ['2026-01-10 10:00:00', '2026-01-10 23:00:00'],
]);

it('names the local calendar date', function () {
    expect(LocalDay::date(CarbonImmutable::parse('2026-01-10 23:30:00', 'UTC')))->toBe('2026-01-11')
        ->and(LocalDay::date(CarbonImmutable::parse('2026-01-10 22:30:00', 'UTC')))->toBe('2026-01-10');
});
