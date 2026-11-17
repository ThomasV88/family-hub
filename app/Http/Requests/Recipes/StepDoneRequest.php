<?php

namespace App\Http\Requests\Recipes;

class StepDoneRequest extends RecipeScopedRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['done' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['done.*' => 'recipes.error.state_invalid'];
    }
}
