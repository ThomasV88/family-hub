<?php

use App\Finance\Recurring\MatchCandidate;
use App\Finance\Recurring\Matcher;
use App\Finance\Recurring\Occurrence;

function occ(string $due, int $tol = 0, int $win = 5, ?string $hint = null, string $acc = 'A', string $id = 'r1'): Occurrence
{
    return new Occurrence($id, $due, 50000, $tol, $win, $acc, $hint);
}

function cand(string $id, string $on, int $amt = -50000, string $kind = 'expense', string $acc = 'A', string $desc = 'landlord'): MatchCandidate
{
    return new MatchCandidate($id, $acc, $on, $amt, $kind, $desc);
}

it('returns null for ineligible candidates', function () {
    $m = new Matcher;
    $o = occ('2027-02-01');

    expect($m->score($o, cand('t', '2027-02-01', acc: 'B')))->toBeNull()
        ->and($m->score($o, cand('t', '2027-02-01', 50000, 'income')))->toBeNull()
        ->and($m->score($o, cand('t', '2027-02-01', 50000, 'transfer')))->toBeNull()
        ->and($m->score($o, cand('t', '2027-02-07')))->toBeNull();
});

it('scores amount, hint and proximity', function () {
    $m = new Matcher;

    expect($m->score(occ('2027-02-01'), cand('t', '2027-02-01')))->toBe(70)
        ->and($m->score(occ('2027-02-01'), cand('t', '2027-02-03')))->toBe(64)
        ->and($m->score(occ('2027-02-01', 1000), cand('t', '2027-02-01', -50800)))->toBe(55)
        ->and($m->score(occ('2027-02-01', 0, 5, 'landlord'), cand('t', '2027-02-01')))->toBe(100)
        ->and($m->score(occ('2027-02-01'), cand('t', '2027-02-01', -40000)))->toBe(20);
});

it('auto-accepts per the rules, including negative transfers', function () {
    $m = new Matcher;

    expect($m->autoAccepts(occ('2027-02-01'), cand('t', '2027-02-01', -50000, 'transfer')))->toBeTrue()
        ->and($m->autoAccepts(occ('2027-02-01'), cand('t', '2027-02-01', -40000)))->toBeFalse()
        ->and($m->autoAccepts(occ('2027-02-01', 0, 5, 'landlord'), cand('t', '2027-02-01', -40000)))->toBeTrue()
        ->and($m->autoAccepts(occ('2027-02-01', 1000), cand('t', '2027-02-02', -50800)))->toBeTrue();
});

it('assigns one payment to the nearest of two overdue months', function () {
    $m = new Matcher;
    $jan = occ('2027-01-28', 0, 10);
    $feb = occ('2027-02-28', 0, 10);
    $out = $m->assign([$jan, $feb], [cand('t1', '2027-02-26')], []);

    expect($out)->toHaveCount(1)
        ->and($out[0]['occurrence'])->toBe($feb->key())
        ->and($out[0]['transaction'])->toBe('t1');
});

it('breaks ties by earlier due date then transaction id and uses each side once', function () {
    $m = new Matcher;
    $a = occ('2027-01-10', 0, 10);
    $b = occ('2027-01-20', 0, 10);
    $out = $m->assign([$b, $a], [cand('t2', '2027-01-15'), cand('t1', '2027-01-15')], []);

    expect($out)->toHaveCount(2)
        ->and($out[0]['occurrence'])->toBe($a->key())->and($out[0]['transaction'])->toBe('t1')
        ->and($out[1]['occurrence'])->toBe($b->key())->and($out[1]['transaction'])->toBe('t2');
});

it('honours rejected pairs, ignores non-accepted pairs and is deterministic', function () {
    $m = new Matcher;
    $o = occ('2027-02-01');
    $cands = [cand('t1', '2027-02-01'), cand('t2', '2027-02-02', -40000)];

    expect($m->assign([$o], $cands, [$o->key().'|t1']))->toBe([])
        ->and($m->assign([$o], $cands, []))->toBe($m->assign([$o], $cands, []))
        ->and($m->assign([$o], $cands, []))->toHaveCount(1);
});

it('ranks manual candidates', function () {
    $m = new Matcher;
    $o = occ('2027-02-01');
    $ranked = $m->rankForManual($o, [
        cand('far', '2027-05-01'),
        cand('other', '2027-02-10', -12345),
        cand('exact', '2027-02-04'),
        cand('income', '2027-02-01', 50000, 'income'),
        cand('acc', '2027-02-01', -50000, 'expense', 'B'),
        cand('tie2', '2027-02-10', -999),
        cand('tie1', '2027-02-10', -998),
    ]);

    expect(array_map(fn ($c) => $c->transactionId, $ranked))->toBe(['exact', 'other', 'tie1', 'tie2']);
});
