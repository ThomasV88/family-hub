<?php

use App\Medical\Ai\QuotaSignatures;
use Carbon\CarbonImmutable;
use Tests\TestCase;

$now = fn () => CarbonImmutable::parse('2026-04-30 09:00:00', 'UTC');

uses(TestCase::class);

it('matches copilot signatures with a curly apostrophe', function () use ($now) {
    expect(QuotaSignatures::match('copilot', "You\u{2019}ve hit your session rate limit.", $now())['minutes'])->toBe(60)
        ->and(QuotaSignatures::match('copilot', "You've reached your weekly rate limit", $now())['minutes'])->toBe(1440)
        ->and(QuotaSignatures::match('copilot', 'hit the rate limit for this model', $now()))->toBeNull()
        ->and(QuotaSignatures::match('copilot', "You've hit the rate limit for this model", $now())['minutes'])->toBe(10);
});

it('parses a codex duration reset with buffer', function () use ($now) {
    $m = QuotaSignatures::match('codex', "You've hit your usage limit. Try again in 1 day 23 hours 37 minutes.", $now());
    expect($m['minutes'])->toBe(60)
        ->and($m['resetAt']->equalTo($now()->addDay()->addHours(23)->addMinutes(37)->addSeconds(60)))->toBeTrue();
});

it('parses absolute, bare and iso reset times', function () use ($now) {
    expect(QuotaSignatures::parseReset('try again at Apr 30th, 2026 11:21 AM', $now())->toIso8601String())
        ->toBe('2026-04-30T11:22:00+00:00');
    $late = CarbonImmutable::parse('2026-04-30 16:00:00', 'UTC');
    expect(QuotaSignatures::parseReset('try again at 3:42 PM', $late)->toIso8601String())
        ->toBe('2026-05-01T15:43:00+00:00');
    expect(QuotaSignatures::parseReset('retry 2026-05-02T03:10:00Z', $now())->toIso8601String())
        ->toBe('2026-05-02T03:11:00+00:00');
});

it('clamps implausible reset times but keeps minutes', function () use ($now) {
    $far = QuotaSignatures::match('codex', 'hit your usage limit, try again in 10 days', $now());
    $near = QuotaSignatures::match('codex', 'hit your usage limit, try again in 20 seconds at 2026-04-30T09:00:20Z', $now());
    expect($far)->toBe(['minutes' => 60, 'resetAt' => null])
        ->and($near)->toBe(['minutes' => 60, 'resetAt' => null]);
});

it('never treats unknown text as quota', function () use ($now) {
    foreach (['segmentation fault', 'rate', 'unauthorized, login again', ''] as $text) {
        expect(QuotaSignatures::match('codex', $text, $now()))->toBeNull()
            ->and(QuotaSignatures::match('copilot', $text, $now()))->toBeNull();
    }
    expect(QuotaSignatures::match('codex', "You've hit your session rate limit", $now()))->toBeNull()
        ->and(QuotaSignatures::match('nobody', 'hit your usage limit', $now()))->toBeNull();
});

it('returns only minutes and resetAt', function () use ($now) {
    $m = QuotaSignatures::match('codex', 'hit your usage limit SECRET-TEXT in 2 hours', $now());
    expect(array_keys($m))->toBe(['minutes', 'resetAt'])
        ->and(json_encode($m))->not->toContain('SECRET');
});
