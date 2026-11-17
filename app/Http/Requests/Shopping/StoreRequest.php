<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:'.(int) config('shopping.name_max.store')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'shopping.error.name_invalid', 'name.max' => 'shopping.error.name_invalid'];
    }
}
