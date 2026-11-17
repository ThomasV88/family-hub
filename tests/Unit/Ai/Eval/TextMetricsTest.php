<?php

use App\Medical\Ai\Eval\TextMetrics;

it('normalises line endings, page separators and whitespace', function () {
    expect(TextMetrics::normalise("  a\r\n b\u{000C}c   d \n"))->toBe('a b c d');
});

it('computes CER over code points', function () {
    expect(TextMetrics::cer('abc', 'abc'))->toBe(0.0)
        ->and(TextMetrics::cer('abc', 'abd'))->toEqualWithDelta(1 / 3, 1e-9)
        ->and(TextMetrics::cer('žltačka', 'zltacka'))->toEqualWithDelta(2 / 7, 1e-9)
        ->and(TextMetrics::cer('', ''))->toBe(0.0)
        ->and(TextMetrics::cer('', 'x'))->toBe(1.0)
        ->and(TextMetrics::cer('abc', ''))->toBe(1.0);
});

it('finishes a 3000 character pair quickly', function () {
    $ref = '';
    $hyp = '';
    for ($i = 0; $i < 3000; $i++) {
        $ref .= chr(97 + (($i * 7 + intdiv($i, 3)) % 26));
        $hyp .= chr(97 + (($i * 11 + intdiv($i, 5)) % 26));
    }
    $t = microtime(true);
    $cer = TextMetrics::cer($ref, $hyp);
    expect(microtime(true) - $t)->toBeLessThan(2.0)
        ->and($cer)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
});

it('computes WER over tokens', function () {
    expect(TextMetrics::wer('one two three four', 'one two tree four'))->toBe(0.25)
        ->and(TextMetrics::wer('a b', 'a b'))->toBe(0.0);
});

it('counts a dropped diacritic as a drop, not as a hit', function () {
    $r = TextMetrics::diacritics('ľadviny boli', 'ladviny boli');
    expect($r['accuracy'])->toBe(0.0)
        ->and($r['drop_rate'])->toBe(1.0)
        ->and($r['empty'])->toBeFalse();

    $ok = TextMetrics::diacritics('ľadviny boli', 'ľadviny boli');
    expect($ok['accuracy'])->toBe(1.0)->and($ok['drop_rate'])->toBe(0.0);
    expect(TextMetrics::diacritics('plain text', 'x')['empty'])->toBeTrue();
});

it('scores numeric tokens exactly', function () {
    expect(TextMetrics::numeric('500 mg 12.03.2026', '500 mg 12.08.2026'))->toBe(0.5)
        ->and(TextMetrics::numeric('no digits here', 'x'))->toBe(1.0);
});

it('measures search recall with the production query rule', function () {
    expect(TextMetrics::searchRecall('Penicilín', 'penicilin'))->toBe(1.0)
        ->and(TextMetrics::searchRecall('Penicilín', 'Penicn'))->toBe(0.0)
        ->and(TextMetrics::searchRecall('Paracetamolu', 'paracetamol'))->toBe(1.0)
        ->and(TextMetrics::searchRecall('a b c', 'x'))->toBe(1.0)
        ->and(TextMetrics::emptyFlags('a b c')['recall'])->toBeTrue();
});
