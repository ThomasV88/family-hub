<?php

namespace App\Http\Requests\Recipes;

class ServingsRequest extends RecipeScopedRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['servings' => ['present', 'nullable', 'integer', 'min:'.(int) config('recipes.servings_min'), 'max:'.(int) config('recipes.servings_max')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['servings.*' => 'recipes.error.servings_invalid'];
    }
}
