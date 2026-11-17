<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function shoppingMigration(): object
{
    return require database_path('migrations/2026_11_28_100000_add_shopping_module_permissions.php');
}

function seedGrant(User $user, string $module, int $level): void
{
    DB::table('module_permissions')->insert(['user_id' => $user->id, 'module' => $module, 'level' => $level, 'created_at' => now(), 'updated_at' => now()]);
}

function shoppingLevel(User $user): ?int
{
    $level = DB::table('module_permissions')->where('user_id', $user->id)->where('module', 'shopping')->value('level');

    return $level === null ? null : (int) $level;
}

it('backfills shopping from the todo level, skips users without todo and keeps existing shopping rows', function () {
    $migration = shoppingMigration();
    $migration->down();

    $none = User::factory()->create();
    $view = User::factory()->create();
    $edit = User::factory()->create();
    $bare = User::factory()->create();
    $kept = User::factory()->create();
    DB::table('module_permissions')->whereIn('user_id', [$none->id, $view->id, $edit->id, $bare->id, $kept->id])->delete();

    seedGrant($none, 'todo', 0);
    seedGrant($view, 'todo', 1);
    seedGrant($edit, 'todo', 2);
    seedGrant($kept, 'todo', 2);

    $migration->up();

    expect(shoppingLevel($none))->toBe(0)
        ->and(shoppingLevel($view))->toBe(1)
        ->and(shoppingLevel($edit))->toBe(2)
        ->and(shoppingLevel($bare))->toBeNull()
        ->and(shoppingLevel($kept))->toBe(2);

    // A shopping row that already exists is never overwritten when the migration runs again.
    DB::table('module_permissions')->where('user_id', $kept->id)->where('module', 'shopping')->update(['level' => 0]);
    $migration->up();
    expect(shoppingLevel($kept))->toBe(0);
});

it('accepts shopping and rejects unknown modules at the database level', function () {
    $user = User::factory()->create();
    DB::table('module_permissions')->where('user_id', $user->id)->delete();

    seedGrant($user, Module::Shopping->value, AccessLevel::View->value);
    expect(shoppingLevel($user))->toBe(1);

    expect(fn () => DB::transaction(fn () => seedGrant($user, 'bogus', 1)))->toThrow(QueryException::class);
});

it('down removes shopping rows and restores the four-module constraint', function () {
    $user = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->create();
    seedGrant($user, 'shopping', 2);

    shoppingMigration()->down();

    expect(shoppingLevel($user))->toBeNull();
    expect(fn () => DB::transaction(fn () => seedGrant($user, 'shopping', 1)))->toThrow(QueryException::class);

    shoppingMigration()->up();
    expect(shoppingLevel($user))->toBe(2);
});
