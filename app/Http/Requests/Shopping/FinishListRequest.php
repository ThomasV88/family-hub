<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class FinishListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('carry_name'))) {
            $this->merge(['carry_name' => trim($this->input('carry_name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['carry_name' => ['nullable', 'string', 'max:'.(int) config('shopping.name_max.list')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['carry_name.*' => 'shopping.error.name_invalid'];
    }
}
