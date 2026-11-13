<?php

use App\Finance\Currency;
use App\Finance\Import\ImportError;
use App\Finance\Import\ProfileSpec;
use App\Finance\Import\RowNormalizer;
use Tests\TestCase;

uses(TestCase::class);

function spec(array $over = []): ProfileSpec
{
    return ProfileSpec::fromArray($over + [
        'delimiter' => ';', 'encoding' => 'UTF-8', 'decimalSeparator' => ',', 'groupingSeparator' => ' ',
        'dateFormat' => 'd.m.Y', 'headerRow' => 1, 'amountMode' => 'single',
        'columns' => [
            'date' => ['name' => 'Dátum', 'index' => 0], 'amount' => ['name' => 'Suma', 'index' => 1],
            'description' => ['name' => 'Popis', 'index' => 2],
        ],
        'indicatorExpense' => ['D', 'MD'], 'indicatorIncome' => ['C'],
    ]);
}

function run(array $rows, ?ProfileSpec $p = null): array
{
    return RowNormalizer::normalize($rows, $p ?? spec(), Currency::EUR, '2026-01-01');
}

it('rejects non-allowlisted profile values', function (array $over) {
    expect(fn () => spec($over))->toThrow(InvalidArgumentException::class);
})->with([
    'delimiter' => [['delimiter' => ':']],
    'encoding' => [['encoding' => 'KOI8-R']],
    'date format' => [['dateFormat' => 'Y/d/m']],
    'grouping = decimal' => [['groupingSeparator' => ',']],
    'mode' => [['amountMode' => 'other']],
    'role' => [['columns' => ['bogus' => ['name' => 'x', 'index' => 1]]]],
    'header row' => [['headerRow' => -1]],
]);

it('round-trips toArray', function () {
    $p = spec();
    expect(ProfileSpec::fromArray($p->toArray())->toArray())->toBe($p->toArray());
});

it('maps by header name even when columns are reordered, falling back to index', function () {
    $r = run([['Popis', 'Suma', 'Dátum'], ['Káva', '-2,50', '05.01.2026']]);
    expect($r[0]->state)->toBe('ok')->and($r[0]->amountMinor)->toBe(-250)->and($r[0]->bookedOn)->toBe('2026-01-05')
        ->and($r[0]->description)->toBe('Káva');
    $p = spec(['columns' => ['date' => ['name' => 'Nope', 'index' => 0], 'amount' => ['name' => null, 'index' => 1]]]);
    $r = run([['x', 'y'], ['05.01.2026', '1,00']], $p);
    expect($r[0]->amountMinor)->toBe(100);
    expect(RowNormalizer::headers([[' Dátum ', 'Suma']], $p))->toBe(['Dátum', 'Suma']);
});

it('throws profile_mismatch when required columns cannot be resolved', function () {
    $p = spec(['columns' => ['date' => ['name' => 'Nope', 'index' => null], 'amount' => ['name' => 'Suma', 'index' => null]]]);
    expect(fn () => run([['a', 'b']], $p))->toThrow(ImportError::class);
});

it('parses single amounts', function () {
    $r = run([['h'], ['01.02.2026', '-1 234,50', 'a'], ['01.02.2026', '+250,00', 'b']]);
    expect([$r[0]->kind, $r[0]->amountMinor, $r[1]->kind, $r[1]->amountMinor])->toBe(['expense', -123450, 'income', 25000]);
});

it('handles split mode', function (array $cells, string $state, ?int $minor, ?string $code) {
    $p = spec(['amountMode' => 'split', 'columns' => [
        'date' => ['name' => null, 'index' => 0], 'expense' => ['name' => null, 'index' => 1], 'income' => ['name' => null, 'index' => 2],
    ]]);
    $r = run([['h'], ['01.02.2026', ...$cells]], $p)[0];
    expect([$r->state, $r->amountMinor, $r->errorCode])->toBe([$state, $minor, $code]);
})->with([
    'debit' => [['12,50', ''], 'ok', -1250, null],
    'credit' => [['', '12,50'], 'ok', 1250, null],
    'negative debit' => [['-12,50', ''], 'ok', -1250, null],
    'both' => [['1,00', '2,00'], 'invalid', null, 'both'],
    'neither' => [['', ''], 'invalid', null, 'amount'],
]);

it('handles indicator mode', function (string $ind, string $state, ?int $minor) {
    $p = spec(['amountMode' => 'indicator', 'columns' => [
        'date' => ['name' => null, 'index' => 0], 'amount' => ['name' => null, 'index' => 1], 'indicator' => ['name' => null, 'index' => 2],
    ]]);
    $r = run([['h'], ['01.02.2026', '12,50', $ind]], $p)[0];
    expect([$r->state, $r->amountMinor])->toBe([$state, $minor]);
})->with([['D', 'ok', -1250], ['md', 'ok', -1250], ['C', 'ok', 1250], ['X', 'invalid', null]]);

it('flags bad rows with codes', function () {
    $p = spec(['columns' => [
        'date' => ['name' => null, 'index' => 0], 'amount' => ['name' => null, 'index' => 1], 'currency' => ['name' => null, 'index' => 2],
    ]]);
    $r = run([['h'], ['Súčet', '1,00'], ['31.02.2026', '1,00', 'EUR'], ['01.02.2026', 'abc', 'EUR'], ['01.02.2026', '0,00', 'EUR'],
        ['01.02.2026', '1,00', 'usd'], ['01.02.2025', '1,00', 'eur'], ['01.02.2026', '1,00', '']], $p);
    expect(array_map(fn ($x) => $x->errorCode, $r))->toBe(['columns', 'date', 'amount', 'amount', 'currency', 'before_open', null])
        ->and($r[0]->rowNo)->toBe(2)->and($r[6]->state)->toBe('ok');
});

it('cleans, truncates and falls back to the counterparty', function () {
    $p = spec(['columns' => [
        'date' => ['name' => null, 'index' => 0], 'amount' => ['name' => null, 'index' => 1],
        'description' => ['name' => null, 'index' => 2], 'counterparty' => ['name' => null, 'index' => 3],
    ]]);
    $r = run([['h'], ['01.02.2026', '1,00', "  a \n  b\t c ", 'X'], ['01.02.2026', '1,00', '', ' Obchod  Žabka '], ['01.02.2026', '1,00', str_repeat('š', 600), str_repeat('p', 300)]], $p);
    expect($r[0]->description)->toBe('a b c')->and($r[1]->description)->toBe('Obchod Žabka')
        ->and(mb_strlen($r[2]->description))->toBe(500)->and(mb_strlen($r[2]->counterparty))->toBe(200);
});
