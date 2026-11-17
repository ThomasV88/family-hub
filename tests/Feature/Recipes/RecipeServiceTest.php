<?php

use App\Access\AccessGate;
use App\Models\Recipe;
use App\Recipes\Images\RecipeImageStore;
use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeService;
use App\Recipes\RecipeStateService;
use App\Recipes\RecipeVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

function rcpData(array $over = []): array
{
    return array_merge([
        'title' => 'Palacinky', 'visibility' => 'private', 'categories' => [],
        'ingredients' => [['name' => 'Múka', 'quantity' => '2'], ['name' => 'Mlieko']],
        'steps' => [['body' => 'Zmiešať'], ['body' => 'Upiecť']],
    ], $over);
}

function rcpStatus(callable $fn): int|string
{
    try {
        $fn();

        return 'ok';
    } catch (ValidationException $e) {
        return 422;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

function rcpErrorKey(callable $fn): string
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->first();
    }

    return '';
}

it('creates a recipe with ordered lines, derived host, deduped categories and search keys', function () {
    $a = RecipeFixtures::member();
    $r = app(RecipeService::class)->create($a, rcpData([
        'source_url' => 'HTTPS://User:pw@Example.COM:8080/x?y=1',
        'categories' => ['Polievka', ' polievka ', 'Dezert'],
        'description' => 'Sladké',
    ]));
    expect($r->author_id)->toBe($a->id)->and($r->source_host)->toBe('example.com');
    expect($r->ingredients()->pluck('name')->all())->toBe(['Múka', 'Mlieko']);
    expect($r->ingredients()->pluck('position')->all())->toBe([1, 2]);
    expect($r->steps()->pluck('body')->all())->toBe(['Zmiešať', 'Upiecť']);
    expect(DB::table('recipe_categories')->where('recipe_id', $r->id)->orderBy('position')->pluck('name')->all())->toBe(['Polievka', 'Dezert']);
    expect($r->title_key)->toBe('palacinky')->and($r->search_key)->toContain('muka')->toContain('polievka')->toContain('sladke');
});

it('refuses too many categories, no ingredients and no steps', function () {
    $a = RecipeFixtures::member();
    $s = app(RecipeService::class);
    expect(rcpErrorKey(fn () => $s->create($a, rcpData(['categories' => ['a', 'b', 'c', 'd']]))))->toBe('recipes.error.categories_count');
    expect(rcpErrorKey(fn () => $s->create($a, rcpData(['ingredients' => []]))))->toBe('recipes.error.ingredients_required');
    expect(rcpErrorKey(fn () => $s->create($a, rcpData(['steps' => []]))))->toBe('recipes.error.steps_required');
    expect(rcpErrorKey(fn () => $s->create($a, rcpData(['source_url' => 'javascript:alert(1)']))))->toBe('recipes.error.source_url_invalid');
    expect(Recipe::count())->toBe(0);
});

it('refuses create without module edit', function () {
    expect(rcpStatus(fn () => app(RecipeService::class)->create(RecipeFixtures::member('view'), rcpData())))->toBe(403);
});

it('updates by id so other members keep ticks and done steps on kept lines', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $s = app(RecipeService::class);
    $state = app(RecipeStateService::class);
    $r = $s->create($a, rcpData(['visibility' => 'shared']));
    [$i1, $i2] = $r->ingredients()->pluck('id')->all();
    [$s1, $s2] = $r->steps()->pluck('id')->all();
    $state->tick($b, $r->id, $i1, true);
    $state->tick($b, $r->id, $i2, true);
    $state->stepDone($b, $r->id, $s1, true);

    $s->update($a, $r->id, rcpData([
        'title' => 'Palacinky 2', 'visibility' => 'shared', 'categories' => ['Dezert'],
        'ingredients' => [['id' => $i1, 'name' => 'Múka', 'quantity' => '5'], ['name' => 'Vajce']],
        'steps' => [['id' => $s1, 'body' => 'Zmiešať dobre'], ['id' => $s2, 'body' => 'Upiecť']],
    ]));

    $fresh = Recipe::find($r->id);
    expect($fresh->ingredients()->count())->toBe(2)->and((float) $fresh->ingredients()->first()->quantity)->toBe(5.0);
    expect($fresh->ingredients()->pluck('id')->contains($i1))->toBeTrue()->and($fresh->ingredients()->pluck('id')->contains($i2))->toBeFalse();
    $st = $state->stateFor($b, $fresh);
    expect($st['tickedIngredientIds'])->toBe([$i1])->and($st['doneStepIds'])->toBe([$s1]);
    expect($fresh->title_key)->toBe('palacinky 2')->and($fresh->search_key)->toContain('vajce')->toContain('dezert')->not->toContain('mlieko');
});

it('refuses a foreign or duplicated line id with unknown_line', function () {
    $a = RecipeFixtures::member();
    $s = app(RecipeService::class);
    $r = $s->create($a, rcpData());
    $other = $s->create($a, rcpData());
    $foreign = $other->ingredients()->value('id');
    $own = $r->ingredients()->value('id');
    expect(rcpErrorKey(fn () => $s->update($a, $r->id, rcpData(['ingredients' => [['id' => $foreign, 'name' => 'x']]]))))->toBe('recipes.error.unknown_line');
    expect(rcpErrorKey(fn () => $s->update($a, $r->id, rcpData(['ingredients' => [['id' => $own, 'name' => 'x'], ['id' => $own, 'name' => 'y']]]))))->toBe('recipes.error.unknown_line');
    expect(rcpErrorKey(fn () => $s->update($a, $r->id, rcpData(['steps' => [['id' => 999999, 'body' => 'x']]]))))->toBe('recipes.error.unknown_line');
    expect($r->ingredients()->count())->toBe(2);
});

it('answers 403 for a non-author on a shared recipe and 404 on a private one', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $s = app(RecipeService::class);
    $shared = RecipeFixtures::sharedRecipe($a);
    $private = RecipeFixtures::privateRecipe($a);
    expect(rcpStatus(fn () => $s->update($b, $shared->id, rcpData())))->toBe(403);
    expect(rcpStatus(fn () => $s->setVisibility($b, $shared->id, RecipeVisibility::Private)))->toBe(403);
    expect(rcpStatus(fn () => $s->delete($b, $shared->id)))->toBe(403);
    expect(rcpStatus(fn () => $s->update($b, $private->id, rcpData())))->toBe(404);
    expect(rcpStatus(fn () => $s->setVisibility($b, $private->id, RecipeVisibility::Shared)))->toBe(404);
    expect(rcpStatus(fn () => $s->delete($b, $private->id)))->toBe(404);
    expect(rcpStatus(fn () => $s->delete($b, 999999)))->toBe(404);
});

it('refuses a view-only author and an author who lost edit', function () {
    $a = RecipeFixtures::member('edit');
    $r = RecipeFixtures::privateRecipe($a);
    RecipeFixtures::member('view'); // unrelated
    $a->modulePermissions()->where('module', 'recipes')->update(['level' => 1]);
    app(AccessGate::class)->forget($a);
    expect(rcpStatus(fn () => app(RecipeService::class)->update($a, $r->id, rcpData())))->toBe(403);
});

it('hides other members state when made private and restores it when re-shared', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $s = app(RecipeService::class);
    $state = app(RecipeStateService::class);
    $r = RecipeFixtures::sharedRecipe($a);
    $state->favourite($b, $r->id, true);
    $state->note($b, $r->id, 'mine');

    $s->setVisibility($a, $r->id, RecipeVisibility::Private);
    expect(rcpStatus(fn () => $state->favourite($b, $r->id, false)))->toBe(404);
    expect(app(RecipeQuery::class)->index($b, ['favourites' => true])['items'])->toHaveCount(0);

    $s->setVisibility($a, $r->id, RecipeVisibility::Shared);
    expect(app(RecipeQuery::class)->index($b, ['favourites' => true])['items'])->toHaveCount(1);
    expect($state->stateFor($b, $r)['note'])->toBe('mine');
});

it('deletes rows and removes the photo directory only after commit', function () {
    $a = RecipeFixtures::member();
    $r = RecipeFixtures::privateRecipe($a);
    $store = Mockery::mock(RecipeImageStore::class);
    $store->shouldReceive('deleteDirectory')->once()->with($r->id);
    app()->instance(RecipeImageStore::class, $store);

    app(RecipeService::class)->delete($a, $r->id);
    expect(Recipe::find($r->id))->toBeNull()->and(DB::table('recipe_ingredients')->where('recipe_id', $r->id)->count())->toBe(0);
});

it('keeps files when the delete is rolled back', function () {
    $a = RecipeFixtures::member();
    $r = RecipeFixtures::privateRecipe($a);
    $store = Mockery::mock(RecipeImageStore::class);
    $store->shouldReceive('deleteDirectory')->never();
    app()->instance(RecipeImageStore::class, $store);
    $service = app(RecipeService::class);

    try {
        DB::transaction(function () use ($service, $a, $r) {
            $service->delete($a, $r->id);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    expect(Recipe::find($r->id))->not->toBeNull();
});

it('has the image store directory contract', function () {
    expect(app(RecipeImageStore::class)->directory(7))->toBe('wish-images/recipes/7');
});
