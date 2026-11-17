<?php

namespace App\Medical\Ai\Eval;

/**
 * Turns the run state (numbers only) into the report: aggregates per arm, the noise floor, the verdicts of the written
 * decision rule and the proposed values of the decisions block. A pure function: it reads no file, holds no model
 * output and has no side effect.
 */
final class EvalReport
{
    private const STEP_NAMES = ['A1' => 'fit', 'A2' => 'autolevel', 'A4' => 'deskew', 'A3' => 'denoise_sharpen'];

    /**
     * Run-to-run standard deviation of CER from the stage B repeats (the same synthetic page sent twice): per page
     * |difference| / sqrt(2), pooled as a root mean square.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function noiseSd(array $rows): float
    {
        $by = [];
        foreach ($rows as $r) {
            if ($r['stage'] === 'B' && ($r['ok'] ?? false) && isset($r['cer'])) {
                $by[$r['item']][] = (float) $r['cer'];
            }
        }
        $sq = [];
        foreach ($by as $v) {
            if (count($v) >= 2) {
                $sq[] = (($v[0] - $v[1]) ** 2) / 2;
            }
        }

        return $sq === [] ? 0.0 : sqrt(Stats::mean($sq));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $meta  generated_at, calls_this_run, generator
     * @return array<string, mixed>
     */
    public static function build(array $state, array $meta = []): array
    {
        $rows = array_values((array) ($state['rows'] ?? []));
        $noise = self::noiseSd($rows);
        if ($noise === 0.0) {
            $noise = (float) ($state['noise_sd'] ?? 0.0);
        }

        $verdicts = self::verdicts($rows, $noise);
        $chain = EvalDecision::greedyChain(array_filter($verdicts, fn ($v) => $v !== null));
        $best = ($verdicts['A5']['keep'] ?? false) ? 'A5' : 'A0';

        return [
            'generated_at' => $meta['generated_at'] ?? null,
            'generator' => $meta['generator'] ?? null,
            'stopped_reason' => $state['stopped_reason'] ?? null,
            'calls' => ['this_run' => $meta['calls_this_run'] ?? null, 'total' => $state['calls_total'] ?? 0, 'projected_total' => $state['projected_total'] ?? null],
            'noise_sd' => round($noise, 5),
            'stages_done' => $state['stages_done'] ?? [],
            'rows' => $rows,
            'aggregates' => self::aggregates($rows),
            'by_condition' => self::byCondition($rows),
            'resolution_probe' => self::probe($rows),
            'verdicts' => $verdicts,
            'chain' => $chain,
            'pipelines' => self::pipelines($rows),
            'copilot' => $state['copilot'] ?? null,
            'decisions' => [
                'preprocess' => $chain === [] ? 'none' : implode('+', array_map(fn ($s) => self::STEP_NAMES[$s], $chain)),
                'best_arm' => $best,
                'pdf_scale' => self::pdfScale($rows),
                'pipeline' => self::pipeline($rows),
                'store_text' => EvalDecision::storeText(array_values(array_filter($rows, fn ($r) => $r['stage'] === 'C' && $r['arm'] === $best && ($r['ok'] ?? false) && isset($r['cer'])))),
                'seconds_per_page' => self::secondsPerPage($rows),
            ],
        ];
    }

    /**
     * A5 against A0 (stage C); then the single steps: A1 against A0, A2 against A1, A4 against A1 (A4 is A1 plus
     * rotation) and A3 against A2, each over the items both arms measured.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{keep: bool, reasons: list<string>, notes: list<string>}|null>
     */
    private static function verdicts(array $rows, float $noise): array
    {
        $set = fn (string $stage, string $arm) => array_values(array_filter(
            $rows,
            fn ($r) => $r['stage'] === $stage && $r['arm'] === $arm && ($r['ok'] ?? false) && isset($r['cer']),
        ));
        $c0 = $set('C', 'A0');
        $pairs = ['A5' => [$c0, $set('C', 'A5')], 'A1' => [$c0, $set('D', 'A1')], 'A2' => [$set('D', 'A1'), $set('D', 'A2')],
            'A4' => [$set('D', 'A1'), $set('D', 'A4')], 'A3' => [$set('D', 'A2'), $set('D', 'A3')]];
        $out = [];
        foreach ($pairs as $name => [$base, $arm]) {
            $ids = array_intersect(array_column($base, 'item'), array_column($arm, 'item'));
            $out[$name] = $ids === [] ? null : EvalDecision::stepVerdict(
                array_values(array_filter($base, fn ($r) => in_array($r['item'], $ids, true))),
                array_values(array_filter($arm, fn ($r) => in_array($r['item'], $ids, true))),
                $noise,
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>> keyed "stage:arm"
     */
    private static function aggregates(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['stage'].':'.$r['arm']][] = $r;
        }
        $out = [];
        foreach ($groups as $key => $g) {
            $ok = array_values(array_filter($g, fn ($r) => $r['ok'] ?? false));
            $mean = function (string $f) use ($ok): ?float {
                $v = array_values(array_filter(array_column($ok, $f), fn ($x) => is_numeric($x)));

                return $v === [] ? null : round(Stats::mean($v), 5);
            };
            $tok = fn (string $k) => ($t = array_values(array_filter(array_map(fn ($r) => $r['tokens'][$k] ?? null, $ok), fn ($x) => $x !== null))) === [] ? null : round(Stats::mean($t), 1);
            $out[$key] = [
                'n' => count($g), 'ok' => count($ok),
                'failures' => array_values(array_unique(array_filter(array_map(fn ($r) => $r['failure'] ?? null, $g)))),
                'cer' => $mean('cer'), 'wer' => $mean('wer'), 'diacritics' => $mean('diacritics'), 'drop_rate' => $mean('drop_rate'),
                'numeric' => $mean('numeric'), 'recall' => $mean('recall'), 'f1' => $mean('f1'),
                'ms' => $mean('ms'), 'pre_ms' => $mean('pre_ms'), 'peak_mb' => $ok === [] ? null : max(array_column($ok, 'peak_mb')),
                'tokens_input' => $tok('input'), 'tokens_output' => $tok('output'), 'tokens_reasoning' => $tok('reasoning'),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, float>> arm => condition => mean CER (stage C and D)
     */
    private static function byCondition(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            if (in_array($r['stage'], ['C', 'D'], true) && ($r['ok'] ?? false) && isset($r['cer'])) {
                $g[$r['arm']][$r['condition']][] = (float) $r['cer'];
            }
        }
        $out = [];
        foreach ($g as $arm => $conds) {
            foreach ($conds as $cond => $v) {
                $out[$arm][$cond] = round(Stats::mean($v), 5);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function probe(array $rows): array
    {
        $arms = [];
        foreach ($rows as $r) {
            if ($r['stage'] === 'A') {
                $arms[$r['arm']] = ['ok' => (bool) $r['ok'], 'cer' => $r['cer'] ?? null, 'recall' => $r['recall'] ?? null, 'tokens_input' => $r['tokens']['input'] ?? null];
            }
        }
        $cers = fn (array $keys) => array_values(array_filter(array_map(fn ($k) => $arms[$k]['cer'] ?? null, $keys), fn ($x) => $x !== null));
        $big = $cers(['p4000png', 'p4000jpg']);
        $small = $cers(['p2048png', 'p2048jpg']);
        $confirmed = $big !== [] && $small !== [] ? abs(Stats::mean($big) - Stats::mean($small)) < 0.01 : null;

        return ['arms' => $arms, 'downscale_confirmed' => $confirmed];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, float|null>>
     */
    private static function pipelines(array $rows): array
    {
        $out = [];
        foreach (['P0', 'P1', 'P2'] as $p) {
            $ok = array_values(array_filter($rows, fn ($r) => $r['stage'] === 'E' && $r['arm'] === $p && ($r['ok'] ?? false) && isset($r['f1'])));
            $out[$p] = $ok === [] ? ['n' => 0.0, 'f1' => null, 'precision' => null, 'recall' => null, 'ms' => null]
                : ['n' => (float) count($ok), 'f1' => round(Stats::mean(array_column($ok, 'f1')), 5), 'precision' => round(Stats::mean(array_column($ok, 'precision')), 5),
                    'recall' => round(Stats::mean(array_column($ok, 'recall_entries')), 5), 'ms' => round(Stats::mean(array_column($ok, 'ms')), 1)];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function pipeline(array $rows): ?string
    {
        $get = fn (string $p) => array_values(array_filter($rows, fn ($r) => $r['stage'] === 'E' && $r['arm'] === $p && ($r['ok'] ?? false) && isset($r['f1'])));
        $p0 = $get('P0');
        $p1 = $get('P1');

        return $p0 === [] || $p1 === [] ? null : EvalDecision::pipeline($p0, $p1);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function pdfScale(array $rows): ?int
    {
        $by = [];
        foreach ($rows as $r) {
            if ($r['stage'] === 'F' && ($r['ok'] ?? false) && isset($r['cer'])) {
                $by[$r['item']][$r['arm']] = (float) $r['cer'];
            }
        }
        $a = $b = [];
        foreach ($by as $v) {
            if (isset($v['PDF1800'], $v['PDF2048'])) {
                $a[] = $v['PDF1800'];
                $b[] = $v['PDF2048'];
            }
        }
        if ($a === []) {
            return null;
        }

        return Stats::mean($a) - Stats::mean($b) >= 0.002 ? 2048 : 1800;
    }

    /**
     * Wall time of one single-page transcription call (all transcription rows that succeeded).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{median: float|null, p90: float|null, n: int}
     */
    private static function secondsPerPage(array $rows): array
    {
        $s = array_values(array_map(
            fn ($r) => $r['ms'] / 1000,
            array_filter($rows, fn ($r) => in_array($r['stage'], ['A', 'B', 'C', 'D', 'F'], true) && ($r['ok'] ?? false) && isset($r['cer'])),
        ));
        if ($s === []) {
            return ['median' => null, 'p90' => null, 'n' => 0];
        }
        sort($s);
        $q = fn (float $p) => $s[(int) min(count($s) - 1, max(0, ceil($p * count($s)) - 1))];

        return ['median' => round($q(0.5), 2), 'p90' => round($q(0.9), 2), 'n' => count($s)];
    }
}
