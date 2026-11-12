<?php

use Tests\TestCase;

uses(TestCase::class);

it('pluralises checks per locale', function (string $locale, int $n, string $expected) {
    expect(trans_choice('common.checks', $n, [], $locale))->toBe($expected);
})->with([
    ['sk', 1, '1 kontrola'],
    ['sk', 3, '3 kontroly'],
    ['sk', 5, '5 kontrol'],
    ['pl', 1, '1 sprawdzenie'],
    ['pl', 2, '2 sprawdzenia'],
    ['pl', 5, '5 sprawdzeń'],
    ['pl', 12, '12 sprawdzeń'],
    ['pl', 22, '22 sprawdzenia'],
    ['en', 1, '1 check'],
    ['en', 2, '2 checks'],
]);
