<?php

use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
    Storage::fake('medical');
    Storage::fake('local');
    $this->synthetic = User::factory()->create(['email' => 'a.1@example.test']);
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function recipeAuditReal(): User
{
    return User::query()->where('email', 'user@real.sk')->first() ?? User::factory()->create(['email' => 'user@real.sk']);
}

/** A synthetic recipe with one ingredient and one step, returned with their ids. */
function recipeAuditRecipe(User $author): array
{
    $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Secret family recipe']);

    return [
        $recipe,
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id]),
        RecipeStep::factory()->create(['recipe_id' => $recipe->id]),
    ];
}

it('passes on the seeded recipe demo', function () {
    $this->artisan('staging:seed --force')->assertExitCode(0);
    expect(Recipe::count())->toBe(12);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('passes with recipe rows owned only by synthetic users', function () {
    [$recipe, $ingredient, $step] = recipeAuditRecipe($this->synthetic);
    DB::table('recipe_member_states')->insert(['recipe_id' => $recipe->id, 'user_id' => $this->synthetic->id, 'favourite' => true]);
    DB::table('recipe_ingredient_ticks')->insert(['ingredient_id' => $ingredient->id, 'user_id' => $this->synthetic->id]);
    DB::table('recipe_step_done')->insert(['step_id' => $step->id, 'user_id' => $this->synthetic->id]);
    RecipeImport::factory()->create(['user_id' => $this->synthetic->id]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails when a recipe is written by a real user and names the table without content', function () {
    recipeAuditRecipe(recipeAuditReal());

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic recipes')
        ->doesntExpectOutputToContain('Secret family recipe')
        ->doesntExpectOutputToContain('real.sk')
        ->assertExitCode(1);
});

it('fails when a real user holds a member state, a tick or a done mark on a synthetic recipe', function (string $table) {
    [$recipe, $ingredient, $step] = recipeAuditRecipe($this->synthetic);
    $real = recipeAuditReal();
    match ($table) {
        'recipe_member_states' => DB::table($table)->insert(['recipe_id' => $recipe->id, 'user_id' => $real->id, 'favourite' => true]),
        'recipe_ingredient_ticks' => DB::table($table)->insert(['ingredient_id' => $ingredient->id, 'user_id' => $real->id]),
        'recipe_step_done' => DB::table($table)->insert(['step_id' => $step->id, 'user_id' => $real->id]),
    };

    $this->artisan('staging:audit')->expectsOutputToContain("non-synthetic {$table}")->assertExitCode(1);
})->with(['recipe_member_states', 'recipe_ingredient_ticks', 'recipe_step_done']);

it('fails when an import belongs to a real user, even a deleted one, without printing the address', function () {
    RecipeImport::factory()->forUrl('https://real-family-blog.example/secret')->create(['user_id' => recipeAuditReal()->id]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic recipe_imports')
        ->doesntExpectOutputToContain('real-family-blog')
        ->assertExitCode(1);

    recipeAuditReal()->delete();
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic recipe_imports')->assertExitCode(1);
});

it('purges synthetic recipes, imports and photo folders on reseed but keeps real ones, so the audit keeps failing', function () {
    $this->artisan('staging:seed --force')->assertExitCode(0);
    $demo = User::where('email', 'like', '%@example.test')->first();
    [$testerRecipe] = recipeAuditRecipe($demo);
    RecipeImport::factory()->create(['user_id' => $demo->id]);
    Storage::disk('local')->put("wish-images/recipes/{$testerRecipe->id}/tester-full.jpg", 'x');
    [$realRecipe] = recipeAuditRecipe(recipeAuditReal());
    Storage::disk('local')->put("wish-images/recipes/{$realRecipe->id}/real-full.jpg", 'x');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(Recipe::count())->toBe(13)
        ->and(Recipe::where('author_id', recipeAuditReal()->id)->count())->toBe(1)
        ->and(DB::table('recipe_imports')->count())->toBe(0)
        ->and(Storage::disk('local')->exists("wish-images/recipes/{$testerRecipe->id}/tester-full.jpg"))->toBeFalse()
        ->and(Storage::disk('local')->exists("wish-images/recipes/{$realRecipe->id}/real-full.jpg"))->toBeTrue();
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic recipes')->assertExitCode(1);
});

it('keeps a demo import draft free of page text: the seed creates no import row at all', function () {
    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(DB::table('recipe_imports')->count())->toBe(0);
});
