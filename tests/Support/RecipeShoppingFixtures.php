<?php

namespace Tests\Support;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Recipe;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingList;
use App\Models\User;
use App\Recipes\RecipeSearchKey;
use App\Shopping\ListStatus;

/** Builders for the recipe to shopping bridge tests. */
final class RecipeShoppingFixtures
{
    /** Recipes edit plus the given Shopping level. */
    public static function member(string $shopping = 'edit'): User
    {
        $level = $shopping === 'edit' ? AccessLevel::Edit : AccessLevel::View;

        return RecipeFixtures::member('edit', [Module::Shopping->value => $level]);
    }

    /** @param list<array{0: string, 1: ?string, 2: ?int}> $ingredients name, quantity, unit id */
    public static function recipeWith(User $author, array $ingredients, int $servings = 4): Recipe
    {
        $recipe = RecipeFixtures::sharedRecipe($author, ['servings' => $servings], 0, 1);
        foreach ($ingredients as $i => [$name, $quantity, $unitId]) {
            $recipe->ingredients()->create([
                'position' => $i + 1, 'name' => $name, 'name_key' => RecipeSearchKey::ingredient($name),
                'quantity' => $quantity, 'unit_id' => $unitId,
            ]);
        }

        return $recipe->refresh();
    }

    public static function catalog(string $name, ?string $unitKey = null): ShoppingCatalogItem
    {
        $item = new ShoppingCatalogItem(['name' => $name, 'default_unit_id' => $unitKey === null ? null : RecipeFixtures::unitId($unitKey)]);
        $item->save();

        return $item;
    }

    public static function openList(User $creator, ?User $contributor = null): ShoppingList
    {
        return ShoppingFixtures::list($creator, null, ListStatus::Open, $contributor === null ? [] : [$contributor]);
    }

    public static function lockedList(User $creator): ShoppingList
    {
        return ShoppingFixtures::list($creator, null, ListStatus::Locked);
    }

    public static function doneList(User $creator): ShoppingList
    {
        return ShoppingFixtures::list($creator, null, ListStatus::Done);
    }
}
