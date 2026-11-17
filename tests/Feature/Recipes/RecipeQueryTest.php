<?php

use App\Models\Recipe;
use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeSearchKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

function rcpIndex($user, array $filters = [], int $page = 1): array
{
    $r = app(RecipeQuery::class)->index($user, $filters, $page);

    return ['titles' => $r['items']->pluck('title')->all(), 'hasMore' => $r['hasMore'], 'items' => $r['items']];
}

function rcpRecipe($author, string $title, string $vis = 'shared', array $ingredients = [], array $categories = [], array $over = []): Recipe
{
    $r = RecipeFixtures::recipe($author, array_merge(['title' => $title, 'visibility' => $vis, 'title_key' => RecipeSearchKey::title($title)], $over), 0, 1);
    foreach ($ingredients as $i => $name) {
        $r->ingredients()->create(['position' => $i + 1, 'name' => $name, 'name_key' => RecipeSearchKey::ingredient($name)]);
    }
    foreach ($categories as $i => $name) {
        DB::table('recipe_categories')->insert(['recipe_id' => $r->id, 'name' => $name, 'name_key' => RecipeSearchKey::category($name), 'position' => $i]);
    }
    $r->forceFill(['search_key' => RecipeSearchKey::build($title, null, $ingredients, $categories)])->save();

    return $r;
}

it('never leaks another member private recipe through any filter', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $hidden = rcpRecipe($a, 'Secret kura', 'private', ['kura'], ['Tajne']);
    DB::table('recipe_member_states')->insert(['recipe_id' => $hidden->id, 'user_id' => $b->id, 'favourite' => true]);

    foreach ([[], ['q' => 'secret'], ['ingredient' => 'kura'], ['categories' => ['tajne']], ['author' => $a->id], ['favourites' => true], ['show' => 'shared']] as $f) {
        expect(rcpIndex($b, $f)['titles'])->toBe([]);
    }
    $opts = app(RecipeQuery::class)->filterOptions($b);
    expect($opts['categories'])->toBe([])->and($opts['authors'])->toBe([]);
    expect(app(RecipeQuery::class)->categorySuggestions($b))->toBe([]);
    expect(fn () => app(RecipeQuery::class)->find($b, $hidden->id))->toThrow(ModelNotFoundException::class);
});

it('finds accent-folded text, needs every token and treats wildcards literally', function () {
    $a = RecipeFixtures::member();
    rcpRecipe($a, 'Žemľovka');
    rcpRecipe($a, 'Kura s ryžou', 'shared', ['kura', 'ryza']);
    rcpRecipe($a, 'Rýchla polievka', 'shared');
    rcpRecipe($a, '100% hit_parade');

    expect(rcpIndex($a, ['q' => 'zemlovka'])['titles'])->toBe(['Žemľovka']);
    expect(rcpIndex($a, ['q' => 'kura ryza'])['titles'])->toBe(['Kura s ryžou']);
    expect(rcpIndex($a, ['q' => 'kura polievka'])['titles'])->toBe([]);
    expect(rcpIndex($a, ['q' => '%'])['titles'])->toBe(['100% hit_parade']);
    expect(rcpIndex($a, ['q' => '_'])['titles'])->toBe(['100% hit_parade']);
    expect(rcpIndex($a, ['q' => 'ke_a'])['titles'])->toBe([]);
});

it('orders title matches before ingredient-only matches', function () {
    $a = RecipeFixtures::member();
    rcpRecipe($a, 'Guláš', 'shared', ['paprika'], [], ['created_at' => now()->addMinute()]);
    rcpRecipe($a, 'Paprika plnená', 'shared', [], [], ['created_at' => now()->subDay()]);
    expect(rcpIndex($a, ['q' => 'paprika'])['titles'])->toBe(['Paprika plnená', 'Guláš']);
    expect(rcpIndex($a)['titles'])->toBe(['Guláš', 'Paprika plnená']);
});

it('filters by ingredient, category, author, mine, shared and favourites', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $r1 = rcpRecipe($a, 'One', 'shared', ['mlieko'], ['Dezert']);
    $r2 = rcpRecipe($b, 'Two', 'shared', ['múka'], ['Polievka', 'Dezert']);
    $r3 = rcpRecipe($a, 'Three', 'private', ['cukor'], ['Raňajky']);
    DB::table('recipe_member_states')->insert(['recipe_id' => $r2->id, 'user_id' => $a->id, 'favourite' => true]);
    DB::table('recipe_member_states')->insert(['recipe_id' => $r1->id, 'user_id' => $b->id, 'favourite' => true]);

    expect(rcpIndex($a, ['ingredient' => 'muka'])['titles'])->toBe(['Two']);
    expect(rcpIndex($a, ['categories' => ['dezert']])['titles'])->toEqualCanonicalizing(['One', 'Two']);
    expect(rcpIndex($a, ['categories' => ['polievka', 'ranajky']])['titles'])->toEqualCanonicalizing(['Two', 'Three']);
    expect(rcpIndex($a, ['author' => $b->id])['titles'])->toBe(['Two']);
    expect(rcpIndex($a, ['show' => 'mine'])['titles'])->toEqualCanonicalizing(['One', 'Three']);
    expect(rcpIndex($a, ['show' => 'shared'])['titles'])->toBe(['Two']);
    expect(rcpIndex($a, ['favourites' => true])['titles'])->toBe(['Two']);
    expect(rcpIndex($b, ['favourites' => true])['titles'])->toBe(['One']);

    $opts = app(RecipeQuery::class)->filterOptions($b);
    expect(collect($opts['categories'])->pluck('key')->all())->toBe(['dezert', 'polievka'])
        ->and(collect($opts['authors'])->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id]);
    expect(app(RecipeQuery::class)->categorySuggestions($b))->toBe(['Dezert', 'Polievka']);
});

it('pages by 24 with hasMore', function () {
    $a = RecipeFixtures::member();
    foreach (range(1, 25) as $i) {
        rcpRecipe($a, "R{$i}", 'shared', [], [], ['created_at' => now()->subMinutes(30 - $i)]);
    }
    $p1 = rcpIndex($a, [], 1);
    $p2 = rcpIndex($a, [], 2);
    expect($p1['titles'])->toHaveCount(24)->and($p1['hasMore'])->toBeTrue()->and($p1['titles'][0])->toBe('R25');
    expect($p2['titles'])->toBe(['R1'])->and($p2['hasMore'])->toBeFalse();
});

it('answers 404 without module view', function () {
    $a = RecipeFixtures::member('none');
    expect(fn () => app(RecipeQuery::class)->index($a, []))->toThrow(NotFoundHttpException::class);
});
