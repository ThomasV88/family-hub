<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** The provider names the member saw in the notice dialog. */
class ImportNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'providers' => ['required', 'array', 'max:10'],
            'providers.*' => ['string', 'max:20'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['errors' => ['providers' => ['invalid']]], 422));
    }
}
