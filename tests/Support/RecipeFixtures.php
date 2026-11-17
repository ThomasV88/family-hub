<?php

namespace Tests\Support;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Recipe;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Recipes\RecipeSearchKey;

/** Shared builders for the Recipes tests. Needs the recipes module CHECK of plan 12-01. */
final class RecipeFixtures
{
    /** @param array<string, AccessLevel> $extraGrants module value => level; $level is none, view or edit */
    public static function member(string $level = 'edit', array $extraGrants = []): User
    {
        $user = User::factory()->create();
        $user->modulePermissions()->updateOrCreate(['module' => Module::Recipes->value], ['level' => AccessLevel::fromLabel($level) ?? AccessLevel::Edit]);
        foreach ($extraGrants as $module => $grant) {
            $user->modulePermissions()->updateOrCreate(['module' => $module], ['level' => $grant]);
        }
        app(AccessGate::class)->forget($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    public static function recipe(User $author, array $overrides = [], int $ingredients = 3, int $steps = 3): Recipe
    {
        $recipe = Recipe::factory()->create(array_merge(['author_id' => $author->id], $overrides));
        for ($i = 1; $i <= $ingredients; $i++) {
            $name = "ingredient {$i}";
            $recipe->ingredients()->create(['position' => $i, 'name' => $name, 'name_key' => RecipeSearchKey::ingredient($name), 'quantity' => $i]);
        }
        for ($i = 1; $i <= $steps; $i++) {
            $recipe->steps()->create(['position' => $i, 'body' => "Step {$i}"]);
        }

        return $recipe;
    }

    /** @param array<string, mixed> $overrides */
    public static function sharedRecipe(User $author, array $overrides = [], int $ingredients = 3, int $steps = 3): Recipe
    {
        return self::recipe($author, array_merge($overrides, ['visibility' => 'shared']), $ingredients, $steps);
    }

    /** @param array<string, mixed> $overrides */
    public static function privateRecipe(User $author, array $overrides = [], int $ingredients = 3, int $steps = 3): Recipe
    {
        return self::recipe($author, array_merge($overrides, ['visibility' => 'private']), $ingredients, $steps);
    }

    public static function unitId(string $key): int
    {
        return (int) ShoppingUnit::where('key', $key)->value('id');
    }
}
