<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class CatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Module access is the route middleware's job.
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
        return [
            'name' => ['required', 'string', 'max:'.(int) config('shopping.name_max.catalog')],
            'default_unit_id' => ['nullable', 'integer', 'exists:shopping_units,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'shopping.error.name_invalid', 'name.max' => 'shopping.error.name_invalid', 'default_unit_id.exists' => 'shopping.error.unit_unknown'];
    }
}
