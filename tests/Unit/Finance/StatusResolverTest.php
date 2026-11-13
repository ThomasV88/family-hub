<?php

use App\Finance\Recurring\BillStatus;
use App\Finance\Recurring\MatchCandidate;
use App\Finance\Recurring\Occurrence;
use App\Finance\Recurring\StatusResolver;

function sOcc(int $tol = 500, int $win = 5): Occurrence
{
    return new Occurrence('r', '2027-02-10', 50000, $tol, $win, 'A');
}

function sCand(string $on, int $amt): MatchCandidate
{
    return new MatchCandidate('t', 'A', $on, $amt, 'expense', 'x');
}

it('derives unmatched statuses from the due date', function () {
    $r = new StatusResolver;

    expect($r->resolve(sOcc(), null, '2027-02-10'))->toBe(BillStatus::Upcoming)
        ->and($r->resolve(sOcc(), null, '2027-02-09'))->toBe(BillStatus::Upcoming)
        ->and($r->resolve(sOcc(), null, '2027-02-11'))->toBe(BillStatus::Overdue);
});

it('derives matched statuses from tolerance and window', function () {
    $r = new StatusResolver;

    expect($r->resolve(sOcc(), sCand('2027-02-12', -50300), '2027-03-01'))->toBe(BillStatus::Paid)
        ->and($r->resolve(sOcc(), sCand('2027-02-12', -51000), '2027-03-01'))->toBe(BillStatus::PaidDifferently)
        ->and($r->resolve(sOcc(), sCand('2027-02-20', -50000), '2027-03-01'))->toBe(BillStatus::PaidDifferently);
});

it('computes the signed difference', function () {
    $r = new StatusResolver;

    expect($r->difference(sOcc(), sCand('2027-02-10', -51000)))->toBe(1000)
        ->and($r->difference(sOcc(), sCand('2027-02-10', -49000)))->toBe(-1000);
});
