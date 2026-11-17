<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge(['quantity' => Quantity::normalise($this->input('quantity'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.(int) config('shopping.name_max.catalog')],
            'quantity' => ['sometimes', ...Quantity::rules(false)],
            'unit_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('shopping.name_max.note')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'shopping.error.name_invalid',
            'quantity.required' => 'shopping.error.quantity',
            'quantity.regex' => 'shopping.error.quantity',
            'unit_id.*' => 'shopping.error.unit_unknown',
            'note.*' => 'shopping.error.note_too_long',
        ];
    }
}
