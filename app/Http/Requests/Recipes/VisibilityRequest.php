<?php

namespace App\Http\Requests\Recipes;

use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;
use Illuminate\Validation\Rule;

class VisibilityRequest extends RecipeScopedRequest
{
    public function authorize(): bool
    {
        $recipe = app(RecipeQuery::class)->visible($this->user(), (int) $this->route('recipe'));

        return app(RecipeAccess::class)->mayEdit($this->user(), $recipe);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['visibility' => ['required', Rule::in(['private', 'shared'])]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['visibility.*' => 'recipes.error.visibility_invalid'];
    }
}
