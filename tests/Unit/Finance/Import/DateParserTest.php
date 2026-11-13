<?php

use App\Finance\Import\DateParser;
use Tests\TestCase;

uses(TestCase::class);

it('parses strictly', function (string $v, string $fmt, ?string $out) {
    expect(DateParser::parse($v, $fmt))->toBe($out);
})->with([
    ['31.01.2026', 'd.m.Y', '2026-01-31'],
    ['31.02.2026', 'd.m.Y', null],
    ['1.2.2026', 'j.n.Y', '2026-02-01'],
    ['2026-02-01 13:45', 'Y-m-d', '2026-02-01'],
    ['2026-02-01T13:45:10', 'Y-m-d', '2026-02-01'],
    ['01/02/26', 'd/m/Y', null],
    ['01.02.26', 'd.m.y', '2026-02-01'],
    ['02/01/2026', 'm/d/Y', '2026-02-01'],
    ['2026-13-01', 'Y-m-d', null],
    ['', 'd.m.Y', null],
    ['abc', 'd.m.Y', null],
    ['01.02.2026 extra', 'd.m.Y', null],
]);

it('rejects a non-allowlisted format', function () {
    DateParser::parse('2026', 'Y');
})->throws(InvalidArgumentException::class);
