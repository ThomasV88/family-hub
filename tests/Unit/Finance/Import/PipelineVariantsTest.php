<?php

use App\Finance\Currency;
use App\Finance\Import\CsvReader;
use App\Finance\Import\Decoder;
use App\Finance\Import\ImportHasher;
use App\Finance\Import\ProfileSpec;
use App\Finance\Import\RowNormalizer;
use Tests\Support\CsvFixtures;
use Tests\TestCase;

uses(TestCase::class);

const BASE_AMOUNTS = [-45000, -12050, 250000, -1234, 123456];

function pipeline(array $opts, array $profile = []): array
{
    $bytes = CsvFixtures::statement($opts);
    $file = Decoder::decode($bytes);
    $delim = CsvReader::guessDelimiter($file->text);
    $records = CsvReader::rows($file->text, $delim, 5000);
    $mode = $opts['amount_mode'] ?? 'single';
    $cols = [
        'date' => ['name' => 'Dátum', 'index' => 0], 'description' => ['name' => 'Popis', 'index' => 1],
        'counterparty' => ['name' => 'Protistrana', 'index' => 2],
    ];
    $cols += match ($mode) {
        'single' => ['amount' => ['name' => 'Suma', 'index' => 3], 'currency' => ['name' => 'Mena', 'index' => 4]],
        'split' => ['expense' => ['name' => 'Ťarcha', 'index' => 3], 'income' => ['name' => 'Kredit', 'index' => 4], 'currency' => ['name' => 'Mena', 'index' => 5]],
        'indicator' => ['amount' => ['name' => 'Suma', 'index' => 3], 'indicator' => ['name' => 'Smer', 'index' => 4], 'currency' => ['name' => 'Mena', 'index' => 5]],
    };
    $p = ProfileSpec::fromArray($profile + [
        'delimiter' => $delim, 'encoding' => $file->encoding,
        'decimalSeparator' => $opts['decimal'] ?? ',', 'groupingSeparator' => in_array($opts['grouping'] ?? ' ', ["\u{00A0}"], true) ? ' ' : ($opts['grouping'] ?? ' '),
        'dateFormat' => $opts['date_format'] ?? 'd.m.Y', 'headerRow' => ($opts['preamble'] ?? 0) + 1,
        'amountMode' => $mode, 'columns' => $cols, 'indicatorExpense' => ['MD'], 'indicatorIncome' => ['D'],
    ]);
    $rows = RowNormalizer::normalize($records, $p, Currency::EUR, '2026-01-01');

    return ImportHasher::apply('acc', $rows);
}

function summarize(array $rows): array
{
    return [
        count(array_filter($rows, fn ($r) => $r->state === 'ok')),
        count(array_filter($rows, fn ($r) => $r->state === 'invalid')),
        array_values(array_map(fn ($r) => $r->amountMinor, array_filter($rows, fn ($r) => $r->state === 'ok'))),
    ];
}

it('normalizes every variant', function (array $opts, int $ok, int $invalid, ?array $amounts) {
    [$o, $i, $a] = summarize(pipeline($opts));
    expect([$o, $i])->toBe([$ok, $invalid]);
    if ($amounts !== null) {
        expect(array_slice($a, 0, count($amounts)))->toBe($amounts);
    }
})->with([
    'semicolon cp1250' => [[], 5, 0, BASE_AMOUNTS],
    'comma dot' => [['delimiter' => ',', 'decimal' => '.', 'grouping' => '', 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'tab' => [['delimiter' => "\t", 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'comma delimiter dot grouping' => [['delimiter' => ';', 'grouping' => '.', 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'nbsp grouping' => [['grouping' => "\u{00A0}", 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'no grouping' => [['grouping' => '', 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'j.n.Y' => [['date_format' => 'j.n.Y'], 5, 0, BASE_AMOUNTS],
    'iso dates' => [['date_format' => 'Y-m-d'], 5, 0, BASE_AMOUNTS],
    'with time' => [['time' => true], 5, 0, BASE_AMOUNTS],
    'preamble' => [['preamble' => 4], 5, 0, BASE_AMOUNTS],
    'footer' => [['footer' => true], 5, 2, BASE_AMOUNTS],
    'preamble and footer' => [['preamble' => 3, 'footer' => true, 'encoding' => 'UTF-8'], 5, 2, BASE_AMOUNTS],
    'split' => [['amount_mode' => 'split'], 5, 0, BASE_AMOUNTS],
    'indicator' => [['amount_mode' => 'indicator'], 5, 0, BASE_AMOUNTS],
    'trailing minus' => [['negative' => 'trailing'], 5, 0, BASE_AMOUNTS],
    'parentheses' => [['negative' => 'parens', 'encoding' => 'UTF-8'], 5, 0, BASE_AMOUNTS],
    'quoted multiline' => [['quoted_rows' => true], 6, 0, [...BASE_AMOUNTS, -150]],
    'diacritics iso' => [['encoding' => 'ISO-8859-2', 'newline' => 'LF'], 5, 0, BASE_AMOUNTS],
    'cp852' => [['encoding' => 'CP852'], 5, 0, null],
    'crlf utf8' => [['encoding' => 'UTF-8', 'newline' => 'CRLF'], 5, 0, BASE_AMOUNTS],
    'lf' => [['newline' => 'LF'], 5, 0, BASE_AMOUNTS],
    'cr' => [['newline' => 'CR'], 5, 0, BASE_AMOUNTS],
    'trailing blanks' => [['trailing_blank' => 3], 5, 0, BASE_AMOUNTS],
    'identical rows' => [['duplicates' => true], 7, 0, [...BASE_AMOUNTS, -250, -250]],
    'wrong currency' => [['wrong_currency' => true], 5, 1, BASE_AMOUNTS],
    'invalid date' => [['invalid_date' => true], 5, 1, BASE_AMOUNTS],
    'utf16le bom' => [['encoding' => 'UTF-16LE-BOM'], 5, 0, BASE_AMOUNTS],
    'utf8 bom' => [['encoding' => 'UTF-8-BOM'], 5, 0, BASE_AMOUNTS],
]);

it('keeps identical same-day rows distinct and hashes overlapping statements the same', function () {
    $a = pipeline(['duplicates' => true]);
    $hashes = array_map(fn ($r) => $r->importHash, $a);
    expect(count(array_unique($hashes)))->toBe(count($hashes));
    $b = pipeline(['duplicates' => true, 'encoding' => 'UTF-8', 'delimiter' => ',', 'decimal' => '.', 'grouping' => '', 'preamble' => 2]);
    expect(array_map(fn ($r) => $r->importHash, $b))->toBe($hashes);
});
