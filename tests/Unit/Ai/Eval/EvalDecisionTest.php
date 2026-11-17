<?php

use App\Medical\Ai\Eval\EvalDecision;

/** @return list<array<string, mixed>> */
function ocrDecRows(array $cers, array $over = [], array $conditions = ['blur', 'rot8', 'lowc', 'noise', 'blur', 'rot8']): array
{
    $rows = [];
    foreach ($cers as $i => $cer) {
        $rows[] = array_merge([
            'item' => 'i'.$i, 'condition' => $conditions[$i] ?? 'clean', 'cer' => $cer,
            'diacritics' => 0.95, 'numeric' => 0.98, 'recall' => 0.9, 'pre_ms' => 800, 'peak_mb' => 70,
        ], $over);
    }

    return $rows;
}

/** Baseline: 6 degraded items (CER 0.08..0.10) plus clean and jpeg. */
function ocrDecBase(): array
{
    return array_merge(
        ocrDecRows([0.08, 0.09, 0.10, 0.09, 0.08, 0.10]),
        [
            ['item' => 'c1', 'condition' => 'clean', 'cer' => 0.01, 'diacritics' => 0.98, 'numeric' => 1.0, 'recall' => 1.0, 'pre_ms' => 0, 'peak_mb' => 0],
            ['item' => 'j1', 'condition' => 'jpeg', 'cer' => 0.02, 'diacritics' => 0.97, 'numeric' => 1.0, 'recall' => 0.98, 'pre_ms' => 0, 'peak_mb' => 0],
        ],
    );
}

function ocrDecArm(float $gain, array $cleanOver = [], array $over = []): array
{
    $rows = [];
    foreach (ocrDecBase() as $r) {
        if (in_array($r['condition'], ['clean', 'jpeg'], true)) {
            $r = array_merge($r, $cleanOver);
        } else {
            $r['cer'] = $r['cer'] - $gain;
        }
        $rows[] = array_merge($r, $over);
    }

    return $rows;
}

it('keeps a step that clearly helps the degraded set', function () {
    $v = EvalDecision::stepVerdict(ocrDecBase(), ocrDecArm(0.02, [], ['pre_ms' => 800, 'peak_mb' => 70]), 0.002);
    expect($v)->toBe(['keep' => true, 'reasons' => []]);
});

it('drops a step that worsens clean CER by 0.3 pp', function () {
    $arm = ocrDecArm(0.02);
    foreach ($arm as &$r) {
        if (in_array($r['condition'], ['clean', 'jpeg'], true)) {
            $r['cer'] += 0.003;
        }
    }
    unset($r);
    $v = EvalDecision::stepVerdict(ocrDecBase(), $arm, 0.002);
    expect($v['keep'])->toBeFalse()->and($v['reasons'])->toContain('clean_regression');
});

it('drops a step that costs 4 seconds per page', function () {
    $v = EvalDecision::stepVerdict(ocrDecBase(), ocrDecArm(0.02, [], ['pre_ms' => 4000]), 0.002);
    expect($v['keep'])->toBeFalse()->and($v['reasons'])->toContain('too_slow');
});

it('drops a step that uses too much memory', function () {
    $v = EvalDecision::stepVerdict(ocrDecBase(), ocrDecArm(0.02, [], ['peak_mb' => 120]), 0.002);
    expect($v['reasons'])->toContain('too_much_memory');
});

it('drops a step whose gain is inside the noise', function () {
    $v = EvalDecision::stepVerdict(ocrDecBase(), ocrDecArm(0.01), 0.01);
    expect($v['keep'])->toBeFalse()->and($v['reasons'])->toContain('gain_below_margin');
});

it('drops a step that lowers search recall', function () {
    $arm = ocrDecArm(0.02);
    foreach ($arm as &$r) {
        $r['recall'] -= 0.05;
    }
    unset($r);
    expect(EvalDecision::stepVerdict(ocrDecBase(), $arm, 0.002)['reasons'])->toContain('recall_drop');
});

it('needs degraded rows', function () {
    $clean = array_values(array_filter(ocrDecBase(), fn ($r) => $r['condition'] === 'clean'));
    expect(EvalDecision::stepVerdict($clean, $clean, 0.0))->toBe(['keep' => false, 'reasons' => ['no_degraded_rows']]);
});

it('builds the chain in the order A1, A2, A4, A3 and stops at the first failure', function () {
    $k = ['keep' => true, 'reasons' => []];
    $f = ['keep' => false, 'reasons' => ['x']];
    expect(EvalDecision::greedyChain(['A1' => $k, 'A2' => $k, 'A4' => $k, 'A3' => $k]))->toBe(['A1', 'A2', 'A4', 'A3'])
        ->and(EvalDecision::greedyChain(['A1' => $k, 'A2' => $f, 'A4' => $k, 'A3' => $k]))->toBe(['A1'])
        ->and(EvalDecision::greedyChain(['A1' => $f, 'A2' => $k]))->toBe([])
        ->and(EvalDecision::greedyChain(['A1' => $k, 'A2' => $k]))->toBe(['A1', 'A2']);
});

it('stores text with a label when the typical set misses the thresholds and never answers no_go', function () {
    $good = array_map(fn ($c) => ['condition' => $c, 'cer' => 0.01, 'diacritics' => 0.99, 'numeric' => 1.0, 'recall' => 0.95], ['clean', 'jpeg', 'rot3', 'blur']);
    expect(EvalDecision::storeText($good))->toBe('go');

    $bad = array_map(fn ($c) => ['condition' => $c, 'cer' => 0.2, 'diacritics' => 0.5, 'numeric' => 0.6, 'recall' => 0.85], ['clean', 'jpeg', 'rot3', 'blur']);
    expect(EvalDecision::storeText($bad))->toBe('go_with_label')
        ->and(EvalDecision::storeText([]))->toBe('go_with_label');

    $lowRecall = array_map(fn ($r) => array_merge($r, ['recall' => 0.85]), $good);
    expect(EvalDecision::storeText($lowRecall))->toBe('go_with_label');
});

it('picks the combined pipeline when the two-call F1 is 3 pp lower', function () {
    expect(EvalDecision::pipeline([['f1' => 0.90]], [['f1' => 0.87]]))->toBe('combined')
        ->and(EvalDecision::pipeline([['f1' => 0.90]], [['f1' => 0.885]]))->toBe('two_call')
        ->and(EvalDecision::pipeline(['f1' => 0.9], ['f1' => 0.95]))->toBe('two_call');
});
