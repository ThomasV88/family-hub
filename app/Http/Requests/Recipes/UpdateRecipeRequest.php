<?php

namespace App\Http\Requests\Recipes;

use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;

class UpdateRecipeRequest extends StoreRecipeRequest
{
    public function authorize(): bool
    {
        // Hidden or unknown recipe is a 404 before the body is read; a visible recipe of someone else is a 403.
        $recipe = app(RecipeQuery::class)->visible($this->user(), (int) $this->route('recipe'));

        return app(RecipeAccess::class)->mayEdit($this->user(), $recipe);
    }

    /** @return array<string, mixed> */
    protected function lineIdRules(string $group): array
    {
        return ["{$group}.*.id" => ['nullable', 'integer']];
    }
}
