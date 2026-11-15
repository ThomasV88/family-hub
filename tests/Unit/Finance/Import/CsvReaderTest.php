<?php

use App\Finance\Import\CsvReader;
use App\Finance\Import\Decoder;
use App\Finance\Import\ImportError;
use Tests\Support\CsvFixtures;
use Tests\TestCase;

uses(TestCase::class);

it('guesses the delimiter', function (array $opts, string $expected) {
    $text = Decoder::decode(CsvFixtures::statement($opts + ['encoding' => 'UTF-8']))->text;
    expect(CsvReader::guessDelimiter($text))->toBe($expected);
})->with([
    'semicolon' => [['delimiter' => ';', 'preamble' => 3, 'footer' => true], ';'],
    'comma' => [['delimiter' => ',', 'decimal' => '.', 'grouping' => ''], ','],
    'tab' => [['delimiter' => "\t"], "\t"],
    'pipe' => [['delimiter' => '|'], '|'],
    'delimiter inside quotes' => [['delimiter' => ';', 'quoted_rows' => true], ';'],
]);

it('reads the SK fixture including preamble and footer records', function () {
    $text = Decoder::decode(file_get_contents(__DIR__.'/../../../fixtures/finance/statement-sk-windows1250.csv'))->text;
    $rows = CsvReader::rows($text, CsvReader::guessDelimiter($text), 5000);
    expect($rows[0])->toBe(['Výpis z účtu', 'SK00 0000 0000 0000 0000 0001'])
        ->and($rows[3])->toBe(['Dátum', 'Popis', 'Protistrana', 'Suma', 'Mena'])
        ->and($rows[6][3])->toBe('2 500,00')
        ->and(end($rows)[0])->toBe('Súčet');
});

it('keeps quoted delimiters, quotes and newlines in one record without deprecations', function () {
    $levels = [];
    set_error_handler(function (int $no) use (&$levels): bool {
        $levels[] = $no;

        return true;
    });
    $text = Decoder::decode(CsvFixtures::statement(['encoding' => 'UTF-8', 'quoted_rows' => true]))->text;
    $rows = CsvReader::rows($text, ';', 5000);
    restore_error_handler();
    expect($levels)->toBe([]);
    $q = collect($rows)->first(fn ($r) => str_starts_with($r[1] ?? '', 'Poplatok'));
    expect($q[1])->toBe("Poplatok; \"špeciál\"\nriadok dva")->and($q[2])->toBe('Banka, a.s.');
});

it('skips fully blank lines and trailing blanks', function () {
    $text = Decoder::decode(CsvFixtures::statement(['encoding' => 'UTF-8', 'trailing_blank' => 3]))->text."\n\n";
    $rows = CsvReader::rows($text, ';', 5000);
    expect($rows)->toHaveCount(6)->and(CsvReader::rows("a;b\n\nc;d\n", ';', 10))->toBe([['a', 'b'], ['c', 'd']]);
});

it('caps data rows', function () {
    $ok = Decoder::decode(CsvFixtures::statement(['encoding' => 'UTF-8', 'rows' => 5000]))->text;
    expect(count(CsvReader::rows($ok, ';', 5000)))->toBe(5001);
    $big = Decoder::decode(CsvFixtures::statement(['encoding' => 'UTF-8', 'rows' => 5001, 'preamble' => 50]))->text;
    expect(fn () => CsvReader::rows($big, ';', 5000))->toThrow(ImportError::class);
    $code = null;
    try {
        CsvReader::rows($big, ';', 5000);
    } catch (ImportError $e) {
        $code = $e->code;
    }
    expect($code)->toBe('too_many_rows');
});
