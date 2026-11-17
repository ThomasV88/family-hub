<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function recipesMigration(): object
{
    return require database_path('migrations/2026_11_29_100000_add_recipes_module_permissions.php');
}

function seedRecipeGrant(User $user, string $module, int $level): void
{
    DB::table('module_permissions')->insert(['user_id' => $user->id, 'module' => $module, 'level' => $level, 'created_at' => now(), 'updated_at' => now()]);
}

function recipesLevel(User $user): ?int
{
    $level = DB::table('module_permissions')->where('user_id', $user->id)->where('module', 'recipes')->value('level');

    return $level === null ? null : (int) $level;
}

it('backfills recipes from the todo level, skips users without todo and keeps existing recipes rows', function () {
    $migration = recipesMigration();
    $migration->down();

    $none = User::factory()->create();
    $view = User::factory()->create();
    $edit = User::factory()->create();
    $bare = User::factory()->create();
    $kept = User::factory()->create();
    DB::table('module_permissions')->whereIn('user_id', [$none->id, $view->id, $edit->id, $bare->id, $kept->id])->delete();

    seedRecipeGrant($none, 'todo', 0);
    seedRecipeGrant($view, 'todo', 1);
    seedRecipeGrant($edit, 'todo', 2);
    seedRecipeGrant($kept, 'todo', 2);

    $migration->up();

    expect(recipesLevel($none))->toBe(0)
        ->and(recipesLevel($view))->toBe(1)
        ->and(recipesLevel($edit))->toBe(2)
        ->and(recipesLevel($bare))->toBeNull()
        ->and(recipesLevel($kept))->toBe(2);

    // A recipes row that already exists is never overwritten when the migration runs again.
    DB::table('module_permissions')->where('user_id', $kept->id)->where('module', 'recipes')->update(['level' => 0]);
    $migration->up();
    expect(recipesLevel($kept))->toBe(0);
});

it('accepts recipes and rejects unknown modules at the database level', function () {
    $user = User::factory()->create();
    DB::table('module_permissions')->where('user_id', $user->id)->delete();

    seedRecipeGrant($user, Module::Recipes->value, AccessLevel::View->value);
    expect(recipesLevel($user))->toBe(1);

    expect(fn () => DB::transaction(fn () => seedRecipeGrant($user, 'bogus', 1)))->toThrow(QueryException::class);
});

it('down removes recipes rows and restores the five-module constraint', function () {
    $user = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->create();
    seedRecipeGrant($user, 'recipes', 2);

    recipesMigration()->down();

    expect(recipesLevel($user))->toBeNull();
    expect(fn () => DB::transaction(fn () => seedRecipeGrant($user, 'recipes', 1)))->toThrow(QueryException::class);

    recipesMigration()->up();
    expect(recipesLevel($user))->toBe(2);
});
