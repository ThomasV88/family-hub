<?php

namespace App\Http\Requests\Recipes;

class TickRequest extends RecipeScopedRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ticked' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ticked.*' => 'recipes.error.state_invalid'];
    }
}
