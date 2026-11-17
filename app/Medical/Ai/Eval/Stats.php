<?php

namespace App\Medical\Ai\Eval;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class Stats
{
    /** @param list<float|int> $v */
    public static function mean(array $v): float
    {
        return $v === [] ? 0.0 : array_sum($v) / count($v);
    }

    /** Sample standard deviation (n - 1); 0 for fewer than two values. @param list<float|int> $v */
    public static function stddev(array $v): float
    {
        $n = count($v);
        if ($n < 2) {
            return 0.0;
        }
        $m = self::mean($v);
        $s = 0.0;
        foreach ($v as $x) {
            $s += ($x - $m) ** 2;
        }

        return sqrt($s / ($n - 1));
    }

    /**
     * 95 percent percentile interval of the mean of per-item differences, resampling items with replacement. The seed
     * is fixed, so the same input gives the same interval.
     *
     * @param  list<float|int>  $diffs
     * @return array{low: float, high: float}
     */
    public static function pairedBootstrap(array $diffs, int $n = 2000, int $seed = 13): array
    {
        $diffs = array_values($diffs);
        $count = count($diffs);
        if ($count === 0) {
            return ['low' => 0.0, 'high' => 0.0];
        }
        $rng = new Randomizer(new Mt19937($seed));
        $means = [];
        for ($i = 0; $i < $n; $i++) {
            $sum = 0.0;
            for ($k = 0; $k < $count; $k++) {
                $sum += $diffs[$rng->getInt(0, $count - 1)];
            }
            $means[] = $sum / $count;
        }
        sort($means);

        return [
            'low' => $means[(int) floor(0.025 * ($n - 1))],
            'high' => $means[(int) ceil(0.975 * ($n - 1))],
        ];
    }
}
