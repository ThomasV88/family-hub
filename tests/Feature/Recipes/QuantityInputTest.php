<?php

use App\Models\Recipe;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->me = RecipeFixtures::member();
});

function rcpQty(mixed $quantity): array
{
    return [
        'title' => 'Q', 'visibility' => 'private',
        'ingredients' => [['name' => 'Rice', 'quantity' => $quantity]],
        'steps' => [['body' => 'Cook']],
    ];
}

it('accepts comma, fractions and vulgar fractions', function (string $typed, float $stored) {
    $this->actingAs($this->me)->postJson('/recipes', rcpQty($typed))->assertCreated();

    expect((float) Recipe::query()->firstOrFail()->ingredients()->value('quantity'))->toBe($stored);
})->with([
    ['0,5', 0.5],
    [' 1,25 ', 1.25],
    ['1/2', 0.5],
    ['1 1/2', 1.5],
    ['½', 0.5],
    ['1⅓', 1.33],
    ['⅔', 0.67],
    ['¾', 0.75],
    ['2/3', 0.67],
    ['9999', 9999.0],
    ['0.01', 0.01],
]);

it('accepts an empty quantity (to taste)', function (mixed $typed) {
    $this->actingAs($this->me)->postJson('/recipes', rcpQty($typed))->assertCreated();

    expect(Recipe::query()->firstOrFail()->ingredients()->value('quantity'))->toBeNull();
})->with([[null], ['']]);

it('refuses exponents, zero, negatives, too large, too precise and text', function (string $typed) {
    $this->actingAs($this->me)->postJson('/recipes', rcpQty($typed))->assertJsonValidationErrors(['ingredients.0.quantity' => 'recipes.error.quantity']);

    expect(Recipe::query()->count())->toBe(0);
})->with(['1e3', '0', '-1', '10000', '1,234', 'abc', '1/0', '0/5', '10000 1/2']);
