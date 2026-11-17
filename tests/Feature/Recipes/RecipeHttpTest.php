<?php

use App\Models\Recipe;
use App\Models\ShoppingUnit;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** @return array<string, mixed> */
function rcpBody(array $over = []): array
{
    return array_merge([
        'title' => '  Tomato   soup ',
        'description' => 'Warm.',
        'servings' => 4,
        'prep_minutes' => 10,
        'cook_minutes' => 20,
        'source_url' => 'https://example.com/soup',
        'visibility' => 'private',
        'categories' => ['Soup'],
        'ingredients' => [['name' => 'Tomato', 'quantity' => '1,5', 'unit_id' => null, 'note' => 'ripe'], ['name' => 'Salt']],
        'steps' => [['body' => 'Chop'], ['body' => 'Boil']],
    ], $over);
}

it('stores a recipe from an Inertia visit with a redirect and toast', function () {
    $me = RecipeFixtures::member();

    $response = $this->actingAs($me)->post('/recipes', rcpBody());

    $recipe = Recipe::query()->firstOrFail();
    $response->assertStatus(303)->assertRedirect("/recipes/{$recipe->id}")->assertSessionHas('toast.key', 'recipes.toast.saved');
    expect($recipe->title)->toBe('Tomato soup')->and($recipe->author_id)->toBe($me->id)
        ->and($recipe->ingredients()->count())->toBe(2)->and($recipe->steps()->count())->toBe(2);
});

it('stores a recipe from a plain JSON request and answers 201 with the id', function () {
    $me = RecipeFixtures::member();

    $response = $this->actingAs($me)->postJson('/recipes', rcpBody());

    $response->assertCreated()->assertExactJson(['id' => Recipe::query()->value('id')]);
});

it('answers an Inertia visit with 303 even when it also accepts JSON', function () {
    $me = RecipeFixtures::member();

    $this->actingAs($me)->withHeaders(['X-Inertia' => 'true'])->postJson('/recipes', rcpBody())->assertStatus(303);
});

it('refuses missing ingredients or steps and invalid fields with the error keys', function () {
    $me = RecipeFixtures::member();

    $this->actingAs($me)->postJson('/recipes', rcpBody(['ingredients' => []]))->assertJsonValidationErrors(['ingredients' => 'recipes.error.ingredients_required']);
    $this->actingAs($me)->postJson('/recipes', rcpBody(['steps' => []]))->assertJsonValidationErrors(['steps' => 'recipes.error.steps_required']);
    $this->actingAs($me)->postJson('/recipes', rcpBody(['title' => ' ']))->assertJsonValidationErrors('title');
    $this->actingAs($me)->postJson('/recipes', rcpBody(['visibility' => 'public']))->assertJsonValidationErrors('visibility');
    $this->actingAs($me)->postJson('/recipes', rcpBody(['servings' => 100]))->assertJsonValidationErrors('servings');
    $this->actingAs($me)->postJson('/recipes', rcpBody(['ingredients' => [['name' => 'x', 'unit_id' => 999999]]]))->assertJsonValidationErrors('ingredients.0.unit_id');
    expect(Recipe::query()->count())->toBe(0);
});

it('refuses non-http source urls and more than three categories', function (string $url) {
    $me = RecipeFixtures::member();

    $this->actingAs($me)->postJson('/recipes', rcpBody(['source_url' => $url]))->assertJsonValidationErrors(['source_url' => 'recipes.error.source_url']);
    expect(Recipe::query()->count())->toBe(0);
})->with(['javascript:alert(1)', 'ftp://x.example/a', 'https://', 'not a url']);

it('refuses four categories', function () {
    $me = RecipeFixtures::member();

    $this->actingAs($me)->postJson('/recipes', rcpBody(['categories' => ['a', 'b', 'c', 'd']]))->assertJsonValidationErrors(['categories' => 'recipes.error.categories']);
});

it('accepts a unit and keeps it', function () {
    $me = RecipeFixtures::member();
    $unit = ShoppingUnit::query()->value('id');

    $this->actingAs($me)->postJson('/recipes', rcpBody(['ingredients' => [['name' => 'Flour', 'quantity' => '2', 'unit_id' => $unit]]]))->assertCreated();

    expect(Recipe::query()->firstOrFail()->ingredients()->value('unit_id'))->toBe($unit);
});

it('refuses create without Recipes edit', function () {
    $viewer = RecipeFixtures::member('view');

    $this->actingAs($viewer)->postJson('/recipes', rcpBody())->assertForbidden();
    $this->actingAs($viewer)->get('/recipes/create')->assertForbidden();
});

it('updates by line id so ticks survive, answers 303 and 200 for JSON', function () {
    $me = RecipeFixtures::member();
    $recipe = RecipeFixtures::privateRecipe($me, [], 2, 2);
    $ids = $recipe->ingredients()->pluck('id')->all();
    $stepIds = $recipe->steps()->pluck('id')->all();
    $body = rcpBody(['title' => 'Renamed', 'ingredients' => [['id' => $ids[0], 'name' => 'Renamed one'], ['id' => $ids[1], 'name' => 'Two'], ['name' => 'New']],
        'steps' => [['id' => $stepIds[0], 'body' => 'A'], ['id' => $stepIds[1], 'body' => 'B']]]);

    $this->actingAs($me)->put("/recipes/{$recipe->id}", $body)->assertStatus(303)->assertRedirect("/recipes/{$recipe->id}")->assertSessionHas('toast.key', 'recipes.toast.saved');
    $this->actingAs($me)->putJson("/recipes/{$recipe->id}", $body)->assertOk()->assertExactJson(['id' => $recipe->id]);

    expect($recipe->fresh()->title)->toBe('Renamed')
        ->and($recipe->ingredients()->pluck('id')->take(2)->all())->toBe($ids)
        ->and($recipe->ingredients()->count())->toBe(3);
});

it('refuses an unknown line id on update', function () {
    $me = RecipeFixtures::member();
    $recipe = RecipeFixtures::privateRecipe($me);

    $this->actingAs($me)->putJson("/recipes/{$recipe->id}", rcpBody(['ingredients' => [['id' => 999999, 'name' => 'x']]]))->assertUnprocessable();
});

it('ignores line ids on store', function () {
    $me = RecipeFixtures::member();
    $other = RecipeFixtures::privateRecipe($me);
    $foreign = $other->ingredients()->value('id');

    $this->actingAs($me)->postJson('/recipes', rcpBody(['ingredients' => [['id' => $foreign, 'name' => 'x']]]))->assertCreated();

    expect($other->ingredients()->count())->toBe(3);
});

it('deletes with 303 to the overview and a toast', function () {
    $me = RecipeFixtures::member();
    $recipe = RecipeFixtures::privateRecipe($me);

    $this->actingAs($me)->delete("/recipes/{$recipe->id}")->assertStatus(303)->assertRedirect('/recipes')->assertSessionHas('toast.key', 'recipes.toast.deleted');

    expect(Recipe::query()->count())->toBe(0);
});

it('changes visibility with a toast and refuses a bad value', function () {
    $me = RecipeFixtures::member();
    $recipe = RecipeFixtures::privateRecipe($me);

    $this->actingAs($me)->put("/recipes/{$recipe->id}/visibility", ['visibility' => 'shared'])->assertStatus(303)->assertSessionHas('toast.key', 'recipes.toast.visibility_changed');
    expect($recipe->fresh()->visibility->value)->toBe('shared');
    $this->actingAs($me)->putJson("/recipes/{$recipe->id}/visibility", ['visibility' => 'x'])->assertJsonValidationErrors('visibility');
    $this->actingAs($me)->putJson("/recipes/{$recipe->id}/visibility", ['visibility' => 'private'])->assertNoContent();
});

it('refuses a non-author on update, delete and visibility with 403', function () {
    $author = RecipeFixtures::member();
    $other = RecipeFixtures::member();
    $recipe = RecipeFixtures::sharedRecipe($author);

    $this->actingAs($other)->putJson("/recipes/{$recipe->id}", rcpBody())->assertForbidden();
    $this->actingAs($other)->deleteJson("/recipes/{$recipe->id}")->assertForbidden();
    $this->actingAs($other)->putJson("/recipes/{$recipe->id}/visibility", ['visibility' => 'private'])->assertForbidden();
    expect($recipe->fresh()->title)->not->toBe('Tomato soup');
});

it('answers 422 on the source address field for credentials in the address', function () {
    $a = RecipeFixtures::member();

    $this->actingAs($a)->postJson('/recipes', rcpBody(['source_url' => 'https://trusted.example@evil.example/']))
        ->assertStatus(422)->assertJsonValidationErrors('source_url');
});
