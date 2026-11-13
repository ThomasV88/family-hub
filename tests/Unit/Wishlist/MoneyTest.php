<?php

use App\Wishlist\Money;

it('converts comma and dot prices to minor units', function (string $in, int $minor) {
    expect(Money::toMinor($in))->toBe($minor);
})->with([['12,50', 1250], ['12.5', 1250], ['0', 0], ['7', 700], ['0.05', 5]]);

it('rejects invalid prices', function (?string $in) {
    expect(Money::toMinor($in))->toBeNull();
})->with(['12.345', '-1', '1e3', '', null, 'abc', '1 000']);

it('formats minor units as a two-decimal string', function () {
    expect(Money::toDecimal(1250))->toBe('12.50')
        ->and(Money::toDecimal(5))->toBe('0.05')
        ->and(Money::toDecimal(0))->toBe('0.00')
        ->and(Money::toDecimal(null))->toBeNull();
});
