<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class StoreListRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,shopping,edit decides; the request only validates.
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
            'name' => ['required', 'string', 'max:'.(int) config('shopping.name_max.list')],
            'store_id' => ['nullable', 'integer'],
            'responsible_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'shopping.error.name_invalid',
            'store_id.*' => 'shopping.error.store_unknown',
            'responsible_id.*' => 'shopping.error.responsible_not_eligible',
        ];
    }
}
