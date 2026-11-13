<?php

use Tests\TestCase;

uses(TestCase::class);

function giftLines(string $locale): array
{
    $lines = require base_path("lang/{$locale}/notifications.php");

    return array_filter($lines, fn ($key) => str_starts_with($key, 'gift_'), ARRAY_FILTER_USE_KEY);
}

function giftPlaceholders(string $value): array
{
    preg_match_all('/:(\w+)/', $value, $m);
    $found = array_unique($m[1]);
    sort($found);

    return array_values($found);
}

it('has the same gift keys in every locale', function (string $locale) {
    $en = giftLines('en');
    $other = giftLines($locale);

    expect(count($en))->toBe(10);
    expect(array_keys($other))->toEqualCanonicalizing(array_keys($en));
    foreach ($other as $key => $value) {
        expect(trim($value))->not->toBe('', $key);
    }
})->with(['sk', 'pl']);

it('keeps every placeholder of en', function (string $locale) {
    $en = giftLines('en');
    $other = giftLines($locale);

    foreach ($en as $key => $value) {
        expect(giftPlaceholders($other[$key]))->toBe(giftPlaceholders($value), $key);
    }
})->with(['sk', 'pl']);

it('has no exclamation marks and no reservation wording', function (string $locale) {
    foreach (giftLines($locale) as $key => $value) {
        expect($value)->not->toContain('!');
        expect(mb_strtolower($value))->not->toMatch('/reserv|rezerv|co-buy|spoločn|wspóln|:title|:price|:amount/u');
    }
})->with(['en', 'sk', 'pl']);

it('renders the reminder title', function () {
    expect(trans('notifications.gift_title', ['name' => 'Ema', 'occasion' => 'narodeniny', 'when' => 'dnes'], 'sk'))
        ->toBe('Ema: narodeniny dnes');
});

it('uses the right plural form for days', function (string $locale, int $n, string $expected) {
    expect(trans_choice('notifications.gift_in_days', $n, [], $locale))->toBe($expected);
})->with([
    ['sk', 1, 'o 1 deň'],
    ['sk', 3, 'o 3 dni'],
    ['sk', 5, 'o 5 dní'],
    ['pl', 1, 'za 1 dzień'],
    ['pl', 2, 'za 2 dni'],
    ['pl', 5, 'za 5 dni'],
    ['en', 1, 'in 1 day'],
    ['en', 2, 'in 2 days'],
]);
