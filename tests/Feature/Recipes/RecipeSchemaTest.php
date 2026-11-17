<?php

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipePhoto;
use App\Models\RecipeStep;
use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

function rcpRawRecipe(array $over = []): int
{
    return DB::table('recipes')->insertGetId(array_merge([
        'author_id' => User::factory()->create()->id, 'title' => 'R',
        'created_at' => now(), 'updated_at' => now(),
    ], $over));
}

function rcpRawIngredient(array $over = []): int
{
    return DB::table('recipe_ingredients')->insertGetId(array_merge([
        'recipe_id' => Recipe::factory()->create()->id, 'name' => 'x', 'name_key' => 'x',
    ], $over));
}

it('rejects out-of-range or unknown recipe column values', function (array $over) {
    rcpRawRecipe($over);
})->with([
    'visibility' => [['visibility' => 'public']],
    'created_via' => [['created_via' => 'ai']],
    'servings 0' => [['servings' => 0]],
    'servings 100' => [['servings' => 100]],
    'prep 1441' => [['prep_minutes' => 1441]],
    'cook 1441' => [['cook_minutes' => 1441]],
])->throws(QueryException::class);

it('accepts boundary recipe values', function () {
    rcpRawRecipe(['servings' => 99, 'prep_minutes' => 0, 'cook_minutes' => 1440]);
    expect(Recipe::count())->toBe(1);
});

it('rejects quantity 0 and 10000 and accepts null and 9999.99 bounds', function (mixed $q, bool $ok) {
    if ($ok) {
        rcpRawIngredient(['quantity' => $q]);
        expect(RecipeIngredient::count())->toBe(1);
    } else {
        expect(fn () => rcpRawIngredient(['quantity' => $q]))->toThrow(QueryException::class);
    }
})->with([[0, false], [10000, false], [null, true], [9999, true], [0.01, true]]);

it('allows only one main photo per recipe', function () {
    $recipe = Recipe::factory()->create();
    RecipePhoto::factory()->count(2)->create(['recipe_id' => $recipe->id]);
    RecipePhoto::factory()->main()->create(['recipe_id' => $recipe->id]);
    expect(fn () => DB::transaction(fn () => RecipePhoto::factory()->main()->create(['recipe_id' => $recipe->id])))->toThrow(QueryException::class);
    RecipePhoto::factory()->main()->create();
    expect(RecipePhoto::count())->toBe(4);
});

it('cascades every child row when a recipe is deleted', function () {
    $author = User::factory()->create();
    $recipe = RecipeFixtures::recipe($author);
    $viewer = User::factory()->create();
    RecipePhoto::factory()->create(['recipe_id' => $recipe->id]);
    DB::table('recipe_categories')->insert(['recipe_id' => $recipe->id, 'name' => 'Soup', 'name_key' => 'soup', 'position' => 0]);
    DB::table('recipe_member_states')->insert(['recipe_id' => $recipe->id, 'user_id' => $viewer->id, 'favourite' => true]);
    DB::table('recipe_ingredient_ticks')->insert(['ingredient_id' => $recipe->ingredients()->first()->id, 'user_id' => $viewer->id]);
    DB::table('recipe_step_done')->insert(['step_id' => $recipe->steps()->first()->id, 'user_id' => $viewer->id]);

    $recipe->delete();

    foreach (['recipe_categories', 'recipe_photos', 'recipe_ingredients', 'recipe_steps', 'recipe_member_states', 'recipe_ingredient_ticks', 'recipe_step_done'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
});

it('removes only its ticks when an ingredient is deleted', function () {
    $recipe = RecipeFixtures::recipe(User::factory()->create());
    $user = User::factory()->create();
    [$a, $b] = $recipe->ingredients()->take(2)->get();
    DB::table('recipe_ingredient_ticks')->insert([['ingredient_id' => $a->id, 'user_id' => $user->id], ['ingredient_id' => $b->id, 'user_id' => $user->id]]);
    $a->delete();
    expect(DB::table('recipe_ingredient_ticks')->count())->toBe(1)
        ->and(RecipeStep::count())->toBe(3);
});

it('nulls the unit when a shopping unit is deleted', function () {
    $unit = ShoppingUnit::factory()->create();
    $ingredient = RecipeIngredient::factory()->create(['unit_id' => $unit->id]);
    $unit->delete();
    expect($ingredient->fresh()->unit_id)->toBeNull();
});

it('refuses to delete the author of a recipe', function () {
    $recipe = Recipe::factory()->create();
    expect(fn () => DB::table('users')->where('id', $recipe->author_id)->delete())->toThrow(QueryException::class);
});

it('keeps member state per member', function () {
    $recipe = Recipe::factory()->create();
    $u = User::factory()->create();
    DB::table('recipe_member_states')->insert(['recipe_id' => $recipe->id, 'user_id' => $u->id]);
    expect(fn () => DB::transaction(fn () => DB::table('recipe_member_states')->insert(['recipe_id' => $recipe->id, 'user_id' => $u->id])))->toThrow(QueryException::class);
    $other = User::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('recipe_member_states')->insert(['recipe_id' => $recipe->id, 'user_id' => $other->id, 'servings' => 100])))->toThrow(QueryException::class);
});

it('lets the app role read and write every recipe table', function () {
    $role = DB::selectOne("select exists (select from pg_roles where rolname = 'fh_app') as e")->e;
    if (! $role) {
        $this->markTestSkipped('role fh_app absent');
    }
    foreach (['recipes', 'recipe_categories', 'recipe_photos', 'recipe_ingredients', 'recipe_steps', 'recipe_member_states', 'recipe_ingredient_ticks', 'recipe_step_done'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect(DB::selectOne('select has_table_privilege(?, ?, ?) as p', ['fh_app', $table, $privilege])->p)->toBeTrue("$table $privilege");
        }
    }
});

it('scopes visibleTo to own and shared recipes', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $own = Recipe::factory()->create(['author_id' => $a->id]);
    $shared = Recipe::factory()->shared()->create(['author_id' => $b->id]);
    Recipe::factory()->create(['author_id' => $b->id]);

    expect(Recipe::query()->visibleTo($a)->pluck('id')->sort()->values()->all())->toBe(collect([$own->id, $shared->id])->sort()->values()->all());
});

it('builds members and recipes through the fixtures', function () {
    $m = RecipeFixtures::member('edit');
    $r = RecipeFixtures::sharedRecipe($m);
    expect($r->ingredients)->toHaveCount(3)->and($r->steps)->toHaveCount(3)
        ->and(RecipeFixtures::unitId('pcs'))->toBeGreaterThan(0);
});
