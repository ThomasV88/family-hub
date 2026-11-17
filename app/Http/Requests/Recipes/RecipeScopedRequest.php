<?php

namespace App\Http\Requests\Recipes;

use App\Recipes\Queries\RecipeQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of every request bound to one recipe. A hidden or unknown recipe is a 404 before the body is read, so a
 * validation error can never tell a hidden recipe from a missing one.
 */
abstract class RecipeScopedRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(RecipeQuery::class)->visible($this->user(), (int) $this->route('recipe'));

        return true;
    }
}
