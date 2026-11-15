<?php

use App\Security\ClampedSeconds;

it('uses sane values as they are', function () {
    expect(ClampedSeconds::from('900', 900, 60, 86400))->toBe(900)
        ->and(ClampedSeconds::from(300, 900, 60, 86400))->toBe(300)
        ->and(ClampedSeconds::from(' 600 ', 600, 60, 3600))->toBe(600);
});

it('clamps out-of-range numbers into the range', function () {
    expect(ClampedSeconds::from('5', 900, 60, 86400))->toBe(60)
        ->and(ClampedSeconds::from('99999999999', 900, 60, 86400))->toBe(86400)
        ->and(ClampedSeconds::from('900000', 600, 60, 3600))->toBe(3600);
});

it('falls back to the default for a malformed, empty, zero or negative value', function () {
    foreach ([null, '', '0', '-5', '15m', '1.5', 'abc', 0, -1, true, [], '1e3'] as $raw) {
        expect(ClampedSeconds::from($raw, 900, 60, 86400))->toBe(900, var_export($raw, true));
    }
});

it('is what config/security.php reads the idle and confirm knobs through', function () {
    $load = function (array $env): array {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        $config = require dirname(__DIR__, 3).'/config/security.php';
        foreach (array_keys($env) as $k) {
            putenv($k);
        }

        return $config;
    };

    expect($load([])['idle_seconds'])->toBe(900)->and($load([])['confirm_window_seconds'])->toBe(600);
    $typo = $load(['SECURITY_IDLE_SECONDS' => '15m', 'SECURITY_CONFIRM_WINDOW_SECONDS' => '0']);
    expect($typo['idle_seconds'])->toBe(900)->and($typo['confirm_window_seconds'])->toBe(600);
    $extreme = $load(['SECURITY_IDLE_SECONDS' => '900000000', 'SECURITY_CONFIRM_WINDOW_SECONDS' => '1']);
    expect($extreme['idle_seconds'])->toBe(86400)->and($extreme['confirm_window_seconds'])->toBe(60);
});
