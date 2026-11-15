<?php

use App\Finance\Import\ImportHasher;
use App\Finance\Import\NormalizedRow;
use Tests\TestCase;

uses(TestCase::class);

function nrow(int $no, string $date, int $amt, string $desc, ?string $ref = null, string $state = 'ok'): NormalizedRow
{
    return new NormalizedRow($no, $date, $amt, $amt < 0 ? 'expense' : 'income', $desc, null, $ref, strtolower($desc), $state);
}

it('gives identical same-day rows distinct hashes via ordinals', function () {
    $h = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -250, 'Kava'), nrow(3, '2026-01-20', -250, 'Kava')]);
    expect($h[0]->importHash)->not->toBe($h[1]->importHash)->and($h[0]->importHash)->toHaveLength(64);
});

it('is stable across overlapping files', function () {
    $a = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -250, 'Kava'), nrow(3, '2026-01-20', -250, 'Kava'), nrow(4, '2026-01-21', -1, 'x')]);
    $b = ImportHasher::apply('acc', [nrow(9, '2026-01-20', -250, 'Kava'), nrow(10, '2026-01-20', -250, 'Kava')]);
    expect([$b[0]->importHash, $b[1]->importHash])->toBe([$a[0]->importHash, $a[1]->importHash]);
});

it('uses the bank reference when present and scopes by account', function () {
    $a = ImportHasher::apply('acc1', [nrow(2, '2026-01-20', -250, 'Kava', 'REF1')]);
    $b = ImportHasher::apply('acc1', [nrow(2, '2026-02-20', -999, 'Other', 'REF1')]);
    $c = ImportHasher::apply('acc2', [nrow(2, '2026-01-20', -250, 'Kava', 'REF1')]);
    $d = ImportHasher::apply('acc2', [nrow(2, '2026-01-20', -250, 'Kava')]);
    $e = ImportHasher::apply('acc1', [nrow(2, '2026-01-20', -250, 'Kava')]);
    expect($a[0]->importHash)->not->toBe($b[0]->importHash)->and($a[0]->importHash)->not->toBe($c[0]->importHash)
        ->and($d[0]->importHash)->not->toBe($e[0]->importHash);
});

it('does not collapse a recycled reference onto a different transaction', function () {
    $old = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -250, 'Rent', '0000')]);
    $new = ImportHasher::apply('acc', [nrow(2, '2026-02-20', -250, 'Rent', '0000')]);
    $other = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -999, 'Rent', '0000')]);
    expect($old[0]->importHash)->not->toBe($new[0]->importHash)->and($old[0]->importHash)->not->toBe($other[0]->importHash);
});

it('keeps two rows with the same reference in one file distinct and stable', function () {
    $a = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -250, 'Shop', '0000'), nrow(3, '2026-01-21', -300, 'Bar', '0000'), nrow(4, '2026-01-20', -250, 'Shop', '0000')]);
    expect(array_unique([$a[0]->importHash, $a[1]->importHash, $a[2]->importHash]))->toHaveCount(3);
    $b = ImportHasher::apply('acc', [nrow(7, '2026-01-20', -250, 'Shop', '0000')]);
    expect($b[0]->importHash)->toBe($a[0]->importHash);
});

it('leaves invalid rows unhashed', function () {
    $h = ImportHasher::apply('acc', [nrow(2, '2026-01-20', -250, 'Kava', null, 'invalid')]);
    expect($h[0]->importHash)->toBeNull();
});
