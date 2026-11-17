<?php

namespace App\Medical\Ai\Eval;

/**
 * The decision rule of the OCR evaluation, written before the run (13-RESEARCH "Decision rule"). Rows are lists of
 * arrays keyed by `item` (the same item ids in the baseline and the arm) with `condition`, `cer`, `diacritics`,
 * `numeric`, `recall` (fractions 0..1), `pre_ms` (pre-processing time of the page) and `peak_mb` (peak PHP memory).
 */
final class EvalDecision
{
    /** Conditions that must not get worse (research rule item 2). */
    public const CLEAN = ['clean', 'jpeg'];

    /** The typical set of the go/no-go for storing text. */
    public const TYPICAL = ['clean', 'jpeg', 'rot3', 'blur'];

    public const ORDER = ['A1', 'A2', 'A4', 'A3'];

    private const MIN_GAIN = 0.005;

    private const REL_GAIN = 0.15;

    private const CLEAN_CER = 0.002;

    private const CLEAN_OTHER = 0.005;

    private const MAX_PRE_MS = 3000.0;

    private const MAX_PEAK_MB = 100.0;

    /**
     * @param  list<array<string, mixed>>  $baselineRows
     * @param  list<array<string, mixed>>  $armRows
     * @return array{keep: bool, reasons: list<string>, notes: list<string>} notes: rules that could not be checked
     */
    public static function stepVerdict(array $baselineRows, array $armRows, float $noiseSd): array
    {
        $base = self::byItem($baselineRows);
        $arm = self::byItem($armRows);
        $items = array_values(array_intersect(array_keys($base), array_keys($arm)));

        $degraded = array_values(array_filter($items, fn ($i) => ! in_array($base[$i]['condition'] ?? '', self::CLEAN, true)));
        $clean = array_values(array_filter($items, fn ($i) => in_array($base[$i]['condition'] ?? '', self::CLEAN, true)));

        $reasons = [];
        if ($degraded === []) {
            return ['keep' => false, 'reasons' => ['no_degraded_rows'], 'notes' => []];
        }

        // 1. gain on the degraded conditions
        $diffs = array_map(fn ($i) => (float) $base[$i]['cer'] - (float) $arm[$i]['cer'], $degraded);
        $baseMean = Stats::mean(array_map(fn ($i) => (float) $base[$i]['cer'], $degraded));
        $gain = Stats::mean($diffs);
        $margin = self::margin($baseMean, $noiseSd);
        if ($gain < $margin) {
            $reasons[] = 'gain_below_margin';
        }
        if (Stats::pairedBootstrap($diffs)['low'] <= 0.0) {
            $reasons[] = 'interval_includes_zero';
        }

        // 2. clean and jpeg must not get worse
        $notes = [];
        if ($clean === []) {
            $notes[] = 'clean_not_measured';
        } else {
            $cerWorse = Stats::mean(array_map(fn ($i) => (float) $arm[$i]['cer'] - (float) $base[$i]['cer'], $clean));
            $diaWorse = Stats::mean(array_map(fn ($i) => (float) $base[$i]['diacritics'] - (float) $arm[$i]['diacritics'], $clean));
            $numWorse = Stats::mean(array_map(fn ($i) => (float) $base[$i]['numeric'] - (float) $arm[$i]['numeric'], $clean));
            if ($cerWorse > self::CLEAN_CER + 1e-9 || $diaWorse > self::CLEAN_OTHER + 1e-9 || $numWorse > self::CLEAN_OTHER + 1e-9) {
                $reasons[] = 'clean_regression';
            }
        }

        // 3. search recall must not drop on either set
        foreach ([$degraded, $clean] as $set) {
            if ($set !== [] && Stats::mean(array_map(fn ($i) => (float) $arm[$i]['recall'] - (float) $base[$i]['recall'], $set)) < -1e-9) {
                $reasons[] = 'recall_drop';
                break;
            }
        }

        // 4. cost
        $pre = Stats::mean(array_map(fn ($i) => (float) ($arm[$i]['pre_ms'] ?? 0), $items));
        if ($pre > self::MAX_PRE_MS) {
            $reasons[] = 'too_slow';
        }
        if (max(array_map(fn ($i) => (float) ($arm[$i]['peak_mb'] ?? 0), $items)) >= self::MAX_PEAK_MB) {
            $reasons[] = 'too_much_memory';
        }

        return ['keep' => $reasons === [], 'reasons' => $reasons, 'notes' => $notes];
    }

    /** The smallest CER gain that counts: 0.5 pp absolute, 15 percent relative or twice the run-to-run noise. */
    public static function margin(float $baseMean, float $noiseSd): float
    {
        return max(self::MIN_GAIN, self::REL_GAIN * $baseMean, 2 * $noiseSd);
    }

    /**
     * Greedy forward selection in the order A1, A2, A4, A3: a step is added only if its own verdict (against the chain so
     * far, computed by the caller) keeps; the first failing step ends the chain.
     *
     * @param  array<string, array{keep: bool}>  $verdicts
     * @return list<string>
     */
    public static function greedyChain(array $verdicts): array
    {
        $chain = [];
        foreach (self::ORDER as $step) {
            if (! isset($verdicts[$step]) || ($verdicts[$step]['keep'] ?? false) !== true) {
                break;
            }
            $chain[] = $step;
        }

        return $chain;
    }

    /**
     * Rows of the typical conditions at the best arm. There is no no_go outcome (owner default): a failing set means the
     * text ships with an "may contain errors" label.
     *
     * @param  list<array<string, mixed>>  $typicalRows
     * @return 'go'|'go_with_label'
     */
    public static function storeText(array $typicalRows): string
    {
        $rows = array_values(array_filter($typicalRows, fn ($r) => in_array($r['condition'] ?? '', self::TYPICAL, true)));
        if ($rows === []) {
            return 'go_with_label';
        }
        $m = fn (string $k) => Stats::mean(array_map(fn ($r) => (float) $r[$k], $rows));

        return $m('cer') <= 0.03 && $m('diacritics') >= 0.97 && $m('numeric') >= 0.99 && $m('recall') >= 0.90
            ? 'go'
            : 'go_with_label';
    }

    /**
     * P1 (two calls) is accepted if its entry F1 is not worse than P0 (today) by more than 2 pp; otherwise P2 (combined).
     * Each argument is a list of rows with an `f1` or an aggregate array with an `f1`.
     *
     * @param  array<int|string, mixed>  $p0
     * @param  array<int|string, mixed>  $p1
     * @return 'two_call'|'combined'
     */
    public static function pipeline(array $p0, array $p1): string
    {
        return self::f1($p1) >= self::f1($p0) - 0.02 - 1e-9 ? 'two_call' : 'combined';
    }

    /** @param array<int|string, mixed> $p */
    private static function f1(array $p): float
    {
        if (isset($p['f1']) && is_numeric($p['f1'])) {
            return (float) $p['f1'];
        }

        return Stats::mean(array_map(fn ($r) => (float) ($r['f1'] ?? 0), array_values($p)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function byItem(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['item']] = $r;
        }

        return $out;
    }
}
