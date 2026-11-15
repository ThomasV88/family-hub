<?php

use App\Finance\Currency;
use App\Finance\MoneyFormat;
use App\Finance\MoneyParser;

it('parses manual amounts exactly', function (string $in, ?int $out) {
    expect(MoneyParser::manual($in, Currency::EUR))->toBe($out);
})->with([
    ['1234,50', 123450], ['1234.50', 123450], ['1 234,50', 123450], ["1\u{00A0}234.5", 123450],
    ['0', null], ['0,01', 1], ['12,345', null], ['1.234,50', null], ['-5', null],
    ['12abc', null], ['', null], [' ', null], ['1,2,3', null], ['+', null], ['5,', null],
    ['9999999999999', null], ['100000000000', 10000000000000], ['100000000000,01', null],
]);

it('allows negatives in manual mode when asked', function () {
    expect(MoneyParser::manual('-5', Currency::EUR, true))->toBe(-500)
        ->and(MoneyParser::manual('--5', Currency::EUR, true))->toBeNull();
});

it('parses csv cells per profile', function (string $in, string $dec, ?string $grp, ?int $out) {
    expect(MoneyParser::csv($in, Currency::EUR, $dec, $grp))->toBe($out);
})->with([
    ['1.234,50', ',', '.', 123450], ['-1.234,50', ',', '.', -123450], ['1.234,50-', ',', '.', -123450],
    ['(12,5)', ',', '.', -1250], ["\u{2212}12,00", ',', '.', -1200], ['+3,00', ',', '.', 300],
    ['12,00 EUR', ',', '.', 1200], ['EUR 12,00', ',', '.', 1200], ['12,00 €', ',', '.', 1200],
    ['12,00 CZK', ',', '.', null], ['1234.5', '.', null, 123450], ['1,234.50', '.', null, null],
    ['0,00', ',', '.', 0],
]);

it('round-trips seeded random amounts', function () {
    mt_srand(20261113);
    for ($i = 0; $i < 1000; $i++) {
        $m = mt_rand(0, 1) ? mt_rand(1, 10000000000000) : -mt_rand(1, 10000000000000);
        if ($i % 7 === 0) {
            $m = mt_rand(-999, 999) ?: 1;
        }
        expect(MoneyParser::csv(MoneyFormat::plain($m, Currency::EUR), Currency::EUR, '.', null))->toBe($m);
    }
});

it('formats plain decimals', function () {
    expect(MoneyFormat::plain(-5, Currency::EUR))->toBe('-0.05')
        ->and(MoneyFormat::plain(123450, Currency::EUR))->toBe('1234.50')
        ->and(MoneyFormat::plain(0, Currency::EUR))->toBe('0.00');
});

it('exposes the currency allowlist', function () {
    expect(Currency::codes())->toBe(['EUR', 'CZK', 'PLN', 'USD', 'GBP'])
        ->and(Currency::tryFrom('HUF'))->toBeNull();
});
