<?php

use App\Recipes\RecipeSearchKey;
use App\Recipes\RecipeVisibility;
use App\Shopping\NameKey;

it('folds accents of every part and joins with spaces', function () {
    expect(RecipeSearchKey::build('Žemľovka', 'Sladká', ['Łosoś', 'Mlieko'], ['Dezert']))
        ->toBe('zemlovka sladka losos mlieko dezert');
});

it('uses only the first 200 characters of the description', function () {
    $key = RecipeSearchKey::build('t', str_repeat('a', 200).'bbb', [], []);
    expect($key)->toBe('t '.str_repeat('a', 200));
});

it('caps the key at 8000 characters', function () {
    $names = array_fill(0, 80, str_repeat('x', 120));
    expect(mb_strlen(RecipeSearchKey::build('t', null, $names, [])))->toBe(8000);
});

it('skips an empty description', function () {
    expect(RecipeSearchKey::build('Soup', null, [], []))->toBe('soup');
});

it('single builders equal NameKey', function () {
    expect(RecipeSearchKey::title('Žemľovka'))->toBe(NameKey::from('Žemľovka'))
        ->and(RecipeSearchKey::category('Łosoś'))->toBe('losos')
        ->and(RecipeSearchKey::ingredient('Mäso'))->toBe(NameKey::from('Mäso'));
});

it('has private and shared visibility', function () {
    expect(RecipeVisibility::Private->value)->toBe('private')
        ->and(RecipeVisibility::Shared->value)->toBe('shared');
});
