<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** The address to import from. Always answers JSON; the reason is a translation key, never the typed value. */
class StartImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['url' => ['required', 'string', 'max:2048']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required' => 'recipes.import.err_url',
            'url.string' => 'recipes.import.err_url',
            'url.max' => 'recipes.import.err_url',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['errors' => ['url' => ['recipes.import.err_url']]], 422));
    }
}
