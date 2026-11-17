<?php

use App\Recipes\Shopping\CatalogMatcher;
use App\Shopping\NameKey;

function recipeMatcherCatalog(string ...$names)
{
    return collect($names)->map(fn ($n, $i) => (object) ['id' => $i + 1, 'name' => $n, 'name_key' => NameKey::from($n)]);
}

function recipeMatch(string $ingredient, string ...$catalog): array
{
    return (new CatalogMatcher)->match($ingredient, recipeMatcherCatalog(...$catalog));
}

it('matches the exact name key ignoring case and accents', function () {
    expect(recipeMatch('Cibuľa', 'cibula'))->toMatchArray(['tier' => 'exact', 'catalogItemId' => 1, 'catalogName' => 'cibula']);
    expect(recipeMatch('  CIBULA ', 'Cibuľa'))->toMatchArray(['tier' => 'exact']);
});

it('prefers the exact item over a longer name', function () {
    expect(recipeMatch('Mlieko', 'Mlieko polotučné', 'Mlieko'))->toMatchArray(['tier' => 'exact', 'catalogItemId' => 2]);
});

it('suggests a single whole-word subset candidate', function () {
    expect(recipeMatch('červená cibuľa', 'Cibuľa'))->toMatchArray(['tier' => 'suggested', 'catalogItemId' => 1, 'catalogName' => 'Cibuľa']);
    expect(recipeMatch('red onion', 'Onion'))->toMatchArray(['tier' => 'suggested']);
});

it('suggests when a plural ending is removed', function () {
    expect(recipeMatch('Pomidory', 'Pomidor'))->toMatchArray(['tier' => 'suggested', 'catalogItemId' => 1]);
    expect(recipeMatch('eggs', 'Egg'))->toMatchArray(['tier' => 'suggested']);
});

it('does not guess irregular plurals (documented outcome: vajcia vs Vajce is none)', function () {
    expect(recipeMatch('vajcia', 'Vajce')['tier'])->toBe('none');
});

it('answers none for several candidates, no candidate and partial words', function () {
    expect(recipeMatch('zelena cibula', 'Cibula', 'Zelena'))->toMatchArray(['tier' => 'none', 'catalogItemId' => null]);
    expect(recipeMatch('Maslo', 'Mlieko'))->toMatchArray(['tier' => 'none']);
    expect(recipeMatch('cibulka', 'Cibul'))->toMatchArray(['tier' => 'none']);
    expect(recipeMatch('', 'Cibula')['tier'])->toBe('none');
    expect(recipeMatch('salt', collect()->all() === [] ? 'Pepper' : 'x')['tier'])->toBe('none');
});

it('never strips an ending that leaves fewer than three characters', function () {
    expect(recipeMatch('ris', 'Ri')['tier'])->toBe('none');
});
