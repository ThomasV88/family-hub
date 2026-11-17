<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $i => $item) {
            if (is_array($item) && array_key_exists('quantity', $item)) {
                $items[$i]['quantity'] = Quantity::normalise($item['quantity']);
            }
        }
        $this->merge(['items' => $items]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('shopping.items_per_add_request')],
            'items.*' => ['array'],
            'items.*.catalog_item_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:'.(int) config('shopping.name_max.catalog')],
            'items.*.quantity' => Quantity::rules(true),
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.note' => ['nullable', 'string', 'max:'.(int) config('shopping.name_max.note')],
            'items.*.save_to_catalog' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'shopping.error.items_count',
            'items.array' => 'shopping.error.items_count',
            'items.min' => 'shopping.error.items_count',
            'items.max' => 'shopping.error.items_count',
            'items.*.array' => 'shopping.error.items_count',
            'items.*.catalog_item_id.*' => 'shopping.error.catalog_unknown',
            'items.*.name.*' => 'shopping.error.name_invalid',
            'items.*.quantity.regex' => 'shopping.error.quantity',
            'items.*.unit_id.*' => 'shopping.error.unit_unknown',
            'items.*.note.*' => 'shopping.error.note_too_long',
            'items.*.save_to_catalog.*' => 'shopping.error.catalog_unknown',
        ];
    }
}
