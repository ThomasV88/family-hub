<?php

namespace App\Recipes;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Recipe;
use App\Models\User;

/**
 * Record-level rights on top of the Recipes module grant. There is no administrator bypass: only the author edits,
 * re-shares or deletes a recipe, and only while the author still holds Recipes edit.
 */
final class RecipeAccess
{
    public function __construct(private readonly AccessGate $gate) {}

    /** Author, or the recipe is shared. Module view is enforced by the route middleware and by the services. */
    public function mayView(User $user, Recipe $recipe): bool
    {
        return $recipe->author_id === $user->id || $recipe->visibility === RecipeVisibility::Shared;
    }

    public function mayEdit(User $user, Recipe $recipe): bool
    {
        return $recipe->author_id === $user->id && $this->gate->allows($user, Module::Recipes, AccessLevel::Edit);
    }

    public function hasView(User $user): bool
    {
        return $this->gate->allows($user, Module::Recipes, AccessLevel::View);
    }

    public function hasEdit(User $user): bool
    {
        return $this->gate->allows($user, Module::Recipes, AccessLevel::Edit);
    }

    /** @return array{edit: bool, delete: bool, changeVisibility: bool, addToShopping: bool} */
    public function abilities(User $user, Recipe $recipe): array
    {
        $edit = $this->mayEdit($user, $recipe);

        return [
            'edit' => $edit,
            'delete' => $edit,
            'changeVisibility' => $edit,
            'addToShopping' => $this->gate->allows($user, Module::Shopping, AccessLevel::Edit),
        ];
    }
}
