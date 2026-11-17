<?php

namespace App\Http\Requests\Recipes;

class FavouriteRequest extends RecipeScopedRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['favourite' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['favourite.*' => 'recipes.error.state_invalid'];
    }
}
