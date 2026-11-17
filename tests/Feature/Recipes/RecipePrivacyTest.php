<?php

use App\Recipes\RecipeStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    config(['app.debug' => false]);
});

/**
 * Every recipe-scoped route with a valid body, for a recipe id and its line ids.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function rcpPrivacyCalls(int $recipe, int $ingredient, int $step): array
{
    return [
        'show' => ['GET', "/recipes/{$recipe}", []],
        'edit' => ['GET', "/recipes/{$recipe}/edit", []],
        'update' => ['PUT', "/recipes/{$recipe}", ['title' => 'Hijack', 'visibility' => 'shared', 'ingredients' => [['name' => 'x']], 'steps' => [['body' => 'y']]]],
        'destroy' => ['DELETE', "/recipes/{$recipe}", []],
        'visibility' => ['PUT', "/recipes/{$recipe}/visibility", ['visibility' => 'shared']],
        'tick' => ['PUT', "/recipes/{$recipe}/ingredients/{$ingredient}/tick", ['ticked' => true]],
        'done' => ['PUT', "/recipes/{$recipe}/steps/{$step}/done", ['done' => true]],
        'favourite' => ['PUT', "/recipes/{$recipe}/favourite", ['favourite' => true]],
        'note' => ['PUT', "/recipes/{$recipe}/note", ['note' => 'x']],
        'servings' => ['PUT', "/recipes/{$recipe}/servings", ['servings' => 2]],
        'reset' => ['DELETE', "/recipes/{$recipe}/progress", []],
    ];
}

/** The body of a 404 never names the recipe (digits are masked: the page carries the same markup either way). */
function rcpMask404(string $body): string
{
    return (string) preg_replace('/\d+/', '#', $body);
}

it('answers every recipe route for a hidden private recipe exactly like an unknown id', function (bool $isAdmin) {
    $author = RecipeFixtures::member();
    $user = RecipeFixtures::member();
    if ($isAdmin) {
        $user->forceFill(['is_admin' => true])->save();
    }
    $recipe = RecipeFixtures::privateRecipe($author, ['title' => 'Hidden cake']);
    $ing = $recipe->ingredients()->value('id');
    $step = $recipe->steps()->value('id');

    $hidden = rcpPrivacyCalls($recipe->id, $ing, $step);
    $unknown = rcpPrivacyCalls(999999, 999999, 999999);
    foreach ($hidden as $name => [$method, $uri, $data]) {
        $a = $this->actingAs($user)->json($method, $uri, $data);
        $b = $this->actingAs($user)->json($method, $unknown[$name][1], $unknown[$name][2]);
        expect($a->getStatusCode())->toBe(404, $name)->and(rcpMask404($a->getContent()))->toBe(rcpMask404($b->getContent()), $name);
    }

    expect($recipe->fresh()->title)->toBe('Hidden cake')->and($recipe->fresh()->visibility->value)->toBe('private');
    $this->assertDatabaseCount('recipe_member_states', 0);
    $this->assertDatabaseCount('recipe_ingredient_ticks', 0);
})->with(['outsider with Recipes edit' => false, 'admin with a Recipes grant' => true]);

it('answers the same 404 for the Inertia pages of a hidden and an unknown recipe', function () {
    $author = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $recipe = RecipeFixtures::privateRecipe($author, ['title' => 'Hidden cake']);

    foreach (['', '/edit'] as $tail) {
        $a = $this->actingAs($other)->get("/recipes/{$recipe->id}{$tail}");
        $b = $this->actingAs($other)->get("/recipes/999999{$tail}");
        expect($a->getStatusCode())->toBe(404)->and(rcpMask404($a->getContent()))->toBe(rcpMask404($b->getContent()));
        expect($a->getContent())->not->toContain('Hidden cake');
    }
});

it('gives a visible non-author 403 on edit, update, destroy and visibility', function () {
    $author = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author, ['title' => 'Shared soup']);
    $calls = rcpPrivacyCalls($recipe->id, $recipe->ingredients()->value('id'), $recipe->steps()->value('id'));

    foreach (['edit', 'update', 'destroy', 'visibility'] as $name) {
        [$method, $uri, $data] = $calls[$name];
        $this->actingAs($other)->json($method, $uri, $data)->assertForbidden();
    }
    expect($recipe->fresh()->title)->toBe('Shared soup');
    $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
});

it('keeps a hidden recipe, its title and its author out of the overview of an outsider', function () {
    $outsider = RecipeFixtures::member();
    $author = RecipeFixtures::member();
    $author->forceFill(['name' => 'Authorzzname'])->save();
    $secret = RecipeFixtures::privateRecipe($author, ['title' => 'Hidden cake']);
    $secret->categories()->create(['name' => 'Secretcat', 'name_key' => 'secretcat', 'position' => 1]);
    $secret->ingredients()->create(['position' => 9, 'name' => 'Saffron', 'name_key' => 'saffron']);

    $response = $this->actingAs($outsider)->get('/recipes')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Index', false)->has('recipes', 0)->has('filterOptions.authors', 0)->has('filterOptions.categories', 0));
    foreach (['Hidden cake', 'Authorzzname', 'Secretcat'] as $needle) {
        expect($response->getContent())->not->toContain($needle);
    }
    // Searching for it, or filtering by its author, finds nothing either.
    $this->actingAs($outsider)->get('/recipes?q=hidden&ingredient=saffron&author='.$author->id)
        ->assertInertia(fn (Assert $p) => $p->component('Recipes/Index', false)->has('recipes', 0));
});

it('hides a recipe again from its former viewer and brings favourite and note back on re-share', function () {
    $author = RecipeFixtures::member();
    $viewer = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author, ['title' => 'Shared soup']);
    app(RecipeStateService::class)->favourite($viewer, $recipe->id, true);
    app(RecipeStateService::class)->note($viewer, $recipe->id, 'my private note');

    $this->actingAs($author)->putJson("/recipes/{$recipe->id}/visibility", ['visibility' => 'private'])->assertNoContent();

    $this->actingAs($viewer)->get('/recipes?favourites=1')->assertInertia(fn (Assert $p) => $p->component('Recipes/Index', false)->has('recipes', 0));
    $this->actingAs($viewer)->get("/recipes/{$recipe->id}")->assertNotFound();

    $this->actingAs($author)->putJson("/recipes/{$recipe->id}/visibility", ['visibility' => 'shared'])->assertNoContent();

    $this->actingAs($viewer)->get('/recipes?favourites=1')->assertInertia(fn (Assert $p) => $p->component('Recipes/Index', false)->has('recipes', 1)->where('recipes.0.favourite', true));
    $this->actingAs($viewer)->get("/recipes/{$recipe->id}")->assertInertia(fn (Assert $p) => $p->component('Recipes/Show', false)->where('state.favourite', true)->where('state.note', 'my private note'));
});

it('never puts another member state into the detail props', function () {
    $author = RecipeFixtures::member();
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author);
    app(RecipeStateService::class)->note($b, $recipe->id, 'note of b only');
    app(RecipeStateService::class)->tick($b, $recipe->id, $recipe->ingredients()->value('id'), true);

    $response = $this->actingAs($a)->get("/recipes/{$recipe->id}")->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Show', false)->where('state.tickedIngredientIds', [])->where('state.note', null));
    expect($response->getContent())->not->toContain('note of b only');
});
