<?php

use App\Medical\Ai\Eval\Stats;

it('computes mean and sample standard deviation', function () {
    expect(Stats::mean([1, 2, 3]))->toBe(2.0)
        ->and(Stats::mean([]))->toBe(0.0)
        ->and(Stats::stddev([2, 4, 4, 4, 5, 5, 7, 9]))->toEqualWithDelta(2.13809, 1e-4)
        ->and(Stats::stddev([5.0]))->toBe(0.0);
});

it('gives a reproducible bootstrap interval', function () {
    $d = [0.01, 0.03, -0.01, 0.02, 0.0, 0.04];
    expect(Stats::pairedBootstrap($d))->toBe(Stats::pairedBootstrap($d));
});

it('puts an all-positive sample above zero and a balanced one around zero', function () {
    expect(Stats::pairedBootstrap([0.02, 0.03, 0.01, 0.04, 0.02])['low'])->toBeGreaterThan(0.0);
    $ci = Stats::pairedBootstrap([0.05, -0.05, 0.04, -0.04, 0.03, -0.03]);
    expect($ci['low'])->toBeLessThan(0.0)->and($ci['high'])->toBeGreaterThan(0.0);
});
