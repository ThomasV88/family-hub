<?php

use App\Wishlist\Occasions\AnnualDate;
use Carbon\CarbonImmutable;

function from(string $s): CarbonImmutable
{
    return CarbonImmutable::parse($s, 'Europe/Bratislava');
}

it('resolves the next annual date on or after today', function (int $m, int $d, string $from, string $expected) {
    expect(AnnualDate::next($m, $d, from($from))->toDateString())->toBe($expected);
})->with([
    'later this year' => [3, 15, '2027-03-01', '2027-03-15'],
    'today counts' => [3, 1, '2027-03-01 10:00', '2027-03-01'],
    'year rollover' => [1, 1, '2027-12-31', '2028-01-01'],
    'feb 29 before non-leap feb 28' => [2, 29, '2027-02-01', '2027-02-28'],
    'feb 29 in leap year' => [2, 29, '2028-02-01', '2028-02-29'],
    'feb 29 after non-leap feb 28 goes to next leap year' => [2, 29, '2027-03-01', '2028-02-29'],
]);

it('keeps the timezone and midnight', function () {
    $r = AnnualDate::next(3, 15, from('2027-03-01 13:45'));
    expect($r->format('H:i'))->toBe('00:00')->and($r->timezoneName)->toBe('Europe/Bratislava');
});

it('counts whole calendar days', function () {
    expect(AnnualDate::daysUntil(from('2027-03-15'), from('2027-03-01 23:30')))->toBe(14)
        ->and(AnnualDate::daysUntil(from('2027-03-01'), from('2027-03-01 08:00')))->toBe(0);
});
