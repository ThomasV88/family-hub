<?php

use App\Recipes\RecipeStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->author = RecipeFixtures::member();
    $this->viewer = RecipeFixtures::member('view');
    $this->recipe = RecipeFixtures::sharedRecipe($this->author);
    $this->ing = $this->recipe->ingredients()->value('id');
    $this->step = $this->recipe->steps()->value('id');
});

it('sets and clears every mark, 303 for Inertia and 204 for JSON, with Recipes view only', function () {
    $id = $this->recipe->id;
    $v = $this->viewer;

    $this->actingAs($v)->put("/recipes/{$id}/ingredients/{$this->ing}/tick", ['ticked' => true])->assertStatus(303);
    $this->actingAs($v)->putJson("/recipes/{$id}/ingredients/{$this->ing}/tick", ['ticked' => true])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/steps/{$this->step}/done", ['done' => true])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/favourite", ['favourite' => true])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/note", ['note' => 'less salt'])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/servings", ['servings' => 6])->assertNoContent();

    $state = app(RecipeStateService::class)->stateFor($v, $this->recipe);
    expect($state)->toMatchArray(['favourite' => true, 'note' => 'less salt', 'servings' => 6, 'tickedIngredientIds' => [$this->ing], 'doneStepIds' => [$this->step]]);

    $this->actingAs($v)->putJson("/recipes/{$id}/ingredients/{$this->ing}/tick", ['ticked' => false])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/note", ['note' => null])->assertNoContent();
    $this->actingAs($v)->putJson("/recipes/{$id}/servings", ['servings' => null])->assertNoContent();
    $state = app(RecipeStateService::class)->stateFor($v, $this->recipe);
    expect($state['tickedIngredientIds'])->toBe([])->and($state['note'])->toBeNull()->and($state['servings'])->toBeNull();
});

it('resets progress with a toast and keeps favourite, note and other members', function () {
    $id = $this->recipe->id;
    $this->actingAs($this->viewer)->putJson("/recipes/{$id}/ingredients/{$this->ing}/tick", ['ticked' => true]);
    $this->actingAs($this->viewer)->putJson("/recipes/{$id}/favourite", ['favourite' => true]);
    $this->actingAs($this->author)->putJson("/recipes/{$id}/ingredients/{$this->ing}/tick", ['ticked' => true]);

    $this->actingAs($this->viewer)->delete("/recipes/{$id}/progress")->assertStatus(303)->assertSessionHas('toast.key', 'recipes.toast.progress_reset');

    $state = app(RecipeStateService::class)->stateFor($this->viewer, $this->recipe);
    expect($state['tickedIngredientIds'])->toBe([])->and($state['favourite'])->toBeTrue()
        ->and(DB::table('recipe_ingredient_ticks')->count())->toBe(1);
    $this->actingAs($this->viewer)->deleteJson("/recipes/{$id}/progress")->assertNoContent();
});

it('refuses bad bodies', function () {
    $id = $this->recipe->id;
    $a = $this->author;

    $this->actingAs($a)->putJson("/recipes/{$id}/ingredients/{$this->ing}/tick", [])->assertJsonValidationErrors('ticked');
    $this->actingAs($a)->putJson("/recipes/{$id}/steps/{$this->step}/done", ['done' => 'maybe'])->assertJsonValidationErrors('done');
    $this->actingAs($a)->putJson("/recipes/{$id}/favourite", [])->assertJsonValidationErrors('favourite');
    $this->actingAs($a)->putJson("/recipes/{$id}/note", ['note' => str_repeat('x', 2001)])->assertJsonValidationErrors(['note' => 'recipes.error.note_too_long']);
    $this->actingAs($a)->putJson("/recipes/{$id}/servings", ['servings' => 0])->assertJsonValidationErrors(['servings' => 'recipes.error.servings_invalid']);
    $this->actingAs($a)->putJson("/recipes/{$id}/servings", ['servings' => 100])->assertJsonValidationErrors('servings');
    $this->actingAs($a)->putJson("/recipes/{$id}/servings", [])->assertJsonValidationErrors('servings');
});

it('answers 404 for a line of another recipe', function () {
    $other = RecipeFixtures::sharedRecipe($this->author);
    $foreign = $other->ingredients()->value('id');

    $this->actingAs($this->viewer)->putJson("/recipes/{$this->recipe->id}/ingredients/{$foreign}/tick", ['ticked' => true])->assertNotFound();
});
