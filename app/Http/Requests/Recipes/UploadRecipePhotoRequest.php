<?php

namespace App\Http\Requests\Recipes;

use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;
use Illuminate\Foundation\Http\FormRequest;

class UploadRecipePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hidden or unknown recipe is a 404 before the body is read; a visible recipe of someone else is a 403.
        $recipe = app(RecipeQuery::class)->visible($this->user(), (int) $this->route('recipe'));

        return app(RecipeAccess::class)->mayEdit($this->user(), $recipe);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:'.(int) ceil((int) config('recipes.photos.max_bytes') / 1024)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'recipes.image.err_type',
            'image.file' => 'recipes.image.err_type',
            'image.uploaded' => 'recipes.image.err_size',
            'image.max' => 'recipes.image.err_size',
        ];
    }
}
