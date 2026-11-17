<?php

use App\Models\RecipePhoto;
use App\Recipes\RecipeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

// The pages do not exist yet (12-07 to 12-09 add them and drop the `false`).

it('lists only visible recipes with the main photo and the favourite flag', function () {
    $me = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $mine = RecipeFixtures::privateRecipe($me, ['title' => 'Mine private']);
    $shared = RecipeFixtures::sharedRecipe($other, ['title' => 'Shared soup']);
    $hidden = RecipeFixtures::privateRecipe($other, ['title' => 'Hidden cake']);
    RecipePhoto::factory()->create(['recipe_id' => $shared->id, 'position' => 1, 'is_main' => true, 'version' => 3]);
    app(RecipeStateService::class)->favourite($me, $shared->id, true);

    $response = $this->actingAs($me)->get('/recipes')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Index')
        ->has('recipes', 2)
        ->where('hasMore', false)
        ->where('page', 1)
        ->where('canCreate', true)
        ->where('filters.show', 'all')
        ->where('recipes.0.id', $shared->id)
        ->where('recipes.0.favourite', true)
        ->where('recipes.0.mainPhoto.version', 3)
        ->where('recipes.0.author.isMe', false)
        ->where('recipes.1.id', $mine->id)
        ->where('recipes.1.favourite', false)
        ->where('recipes.1.mainPhoto', null));

    expect($response->getContent())->not->toContain('Hidden cake');
    expect($hidden->id)->not->toBe($shared->id);
});

it('pages the overview 24 at a time and echoes the filters', function () {
    $me = RecipeFixtures::member();
    for ($i = 0; $i < 25; $i++) {
        RecipeFixtures::sharedRecipe($me, ['title' => 'Soup number '.$i, 'title_key' => 'soup number '.$i, 'search_key' => 'soup number '.$i], 1, 1);
    }

    $this->actingAs($me)->get('/recipes')->assertInertia(fn (Assert $p) => $p->component('Recipes/Index')->has('recipes', 24)->where('hasMore', true));
    $this->actingAs($me)->get('/recipes?page=2&show=mine&q=%20soup%20')->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Index')->has('recipes', 1)->where('hasMore', false)->where('page', 2)
        ->where('filters.show', 'mine')->where('filters.q', 'soup'));
});

it('filters by favourites, shows and refuses a bad show value', function () {
    $me = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $a = RecipeFixtures::sharedRecipe($other);
    RecipeFixtures::sharedRecipe($other);
    app(RecipeStateService::class)->favourite($me, $a->id, true);

    $this->actingAs($me)->get('/recipes?favourites=1')->assertInertia(fn (Assert $p) => $p->component('Recipes/Index')->has('recipes', 1)->where('recipes.0.id', $a->id));
    $this->actingAs($me)->get('/recipes?show=bogus')->assertSessionHasErrors('show');
});

it('offers filter options from visible recipes only', function () {
    $me = RecipeFixtures::member();
    $stranger = RecipeFixtures::member();
    $visible = RecipeFixtures::sharedRecipe($stranger);
    $visible->categories()->create(['name' => 'Soup', 'name_key' => 'soup', 'position' => 1]);
    $secret = RecipeFixtures::privateRecipe($stranger);
    $secret->categories()->create(['name' => 'Secretcat', 'name_key' => 'secretcat', 'position' => 1]);

    $this->actingAs($me)->get('/recipes')->assertInertia(fn (Assert $p) => $p->component('Recipes/Index')
        ->has('filterOptions.categories', 1)->where('filterOptions.categories.0.name', 'Soup')->has('filterOptions.authors', 1));
});

it('shows the detail with lines, photos, units and only the own state', function () {
    $author = RecipeFixtures::member();
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author, ['servings' => 4]);
    $ing = $recipe->ingredients()->first();
    $photo = RecipePhoto::factory()->create(['recipe_id' => $recipe->id, 'position' => 1, 'is_main' => true, 'version' => 2]);
    app(RecipeStateService::class)->tick($b, $recipe->id, $ing->id, true);
    app(RecipeStateService::class)->note($b, $recipe->id, 'secret note of b');
    app(RecipeStateService::class)->favourite($a, $recipe->id, true);

    $response = $this->actingAs($a)->get("/recipes/{$recipe->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Show', false)
        ->where('recipe.id', $recipe->id)
        ->has('recipe.ingredients', 3)->has('recipe.steps', 3)
        ->where('recipe.ingredients.0.quantity', '1')
        ->where('recipe.photos.0.thumbUrl', "/recipes/{$recipe->id}/photos/{$photo->id}/thumb?v=2")
        ->where('recipe.photos.0.fullUrl', "/recipes/{$recipe->id}/photos/{$photo->id}/full?v=2")
        ->where('state.favourite', true)->where('state.tickedIngredientIds', [])->where('state.note', null)
        ->where('abilities.edit', false)->where('abilities.delete', false)
        ->has('units'));

    expect($response->getContent())->not->toContain('secret note of b')->not->toContain('_path');
});

it('gives the author edit abilities and the form props', function () {
    $author = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author);

    $this->actingAs($author)->get("/recipes/{$recipe->id}")->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Show', false)->where('abilities.edit', true)->where('abilities.changeVisibility', true));
    $this->actingAs($author)->get('/recipes/create')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Form')->where('recipe', null)->where('defaults.servings', 4)->where('defaults.visibility', 'private')
        ->has('starterCategories', 10)->has('units')->where('limits.titleMax', 120)->where('isShared', false));
    $this->actingAs($author)->get("/recipes/{$recipe->id}/edit")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Form')->where('recipe.id', $recipe->id)->where('isShared', true)->has('recipe.ingredients', 3));
});

it('refuses the edit page to a visible non-author and hides a private recipe', function () {
    $author = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $shared = RecipeFixtures::sharedRecipe($author);
    $private = RecipeFixtures::privateRecipe($author);

    $this->actingAs($other)->get("/recipes/{$shared->id}/edit")->assertForbidden();
    $this->actingAs($other)->get("/recipes/{$private->id}/edit")->assertNotFound();
    $this->actingAs($other)->get("/recipes/{$private->id}")->assertNotFound();
    $this->actingAs($other)->get('/recipes/999999')->assertNotFound();
});
