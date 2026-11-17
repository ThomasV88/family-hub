<?php

namespace App\Http\Requests\Recipes;

use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;
use Illuminate\Foundation\Http\FormRequest;

class MoveRecipePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recipe = app(RecipeQuery::class)->visible($this->user(), (int) $this->route('recipe'));

        return app(RecipeAccess::class)->mayEdit($this->user(), $recipe);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['direction' => ['required', 'in:earlier,later']];
    }
}
