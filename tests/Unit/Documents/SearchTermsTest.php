<?php

use App\Medical\Documents\Text\SearchTerms;

it('builds prefix alternatives per token', function () {
    expect(SearchTerms::parse('Paracetamolu 500 mg!'))->toBe([['paracetamolu', 'paracetamol', 'paracetamo'], ['500'], ['mg']])
        ->and(SearchTerms::parse('a'))->toBe([])
        ->and(SearchTerms::parse('a b'))->toBe([])
        ->and(SearchTerms::parse(''))->toBe([]);
});

it('keeps at most eight tokens and the first hundred characters', function () {
    expect(SearchTerms::parse('aa bb cc dd ee ff gg hh ii'))->toHaveCount(8);

    $parsed = SearchTerms::parse(str_repeat('x', 98).' tail');
    expect($parsed)->toHaveCount(1)
        ->and(mb_strlen($parsed[0][0]))->toBe(98);
});

it('does not trim tokens below the minimum length', function () {
    expect(SearchTerms::parse('penicil'))->toBe([['penicil', 'penici', 'penic']])
        ->and(SearchTerms::parse('lieky'))->toBe([['lieky']])
        ->and(SearchTerms::parse('liekyy'))->toBe([['liekyy']]);
});

it('folds Slovak and Polish letters like the search will', function () {
    expect(SearchTerms::fold('Łukasz'))->toBe('lukasz')
        ->and(SearchTerms::fold('Ťažká'))->toBe('tazka')
        ->and(SearchTerms::fold('ĽADVINY'))->toBe('ladviny');
});

it('matches a folded word that starts with any alternative', function () {
    expect(SearchTerms::matches(['lukasz'], 'Łukaszowi'))->toBeTrue()
        ->and(SearchTerms::matches(['paracetamolu', 'paracetamol', 'paracetamo'], 'paracetamol'))->toBeTrue()
        ->and(SearchTerms::matches(['paracetamol'], 'aspirin'))->toBeFalse()
        ->and(SearchTerms::matches([], 'x'))->toBeFalse();
});
