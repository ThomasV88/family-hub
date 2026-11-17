<?php

namespace App\Http\Requests\Recipes;

use App\Http\Requests\Shopping\Quantity;
use Illuminate\Validation\Validator;

class AddToShoppingRequest extends RecipeScopedRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('name'))) {
            $merge['name'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('name')));
        }
        $items = $this->input('items');
        if (is_array($items)) {
            foreach ($items as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (array_key_exists('quantity', $item)) {
                    $items[$i]['quantity'] = Quantity::normalise($item['quantity']);
                }
                if (isset($item['name']) && is_string($item['name'])) {
                    $items[$i]['name'] = trim((string) preg_replace('/\s+/u', ' ', $item['name']));
                }
            }
            $merge['items'] = $items;
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target' => ['required', 'in:new,existing'],
            'list_id' => ['required_if:target,existing', 'nullable', 'integer'],
            'name' => ['required_if:target,new', 'nullable', 'string', 'max:'.(int) config('recipes.shopping.list_name_max')],
            'store_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('recipes.shopping.items_max')],
            'items.*' => ['array'],
            'items.*.catalog_item_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:'.(int) config('shopping.name_max.catalog')],
            'items.*.quantity' => Quantity::rules(false),
            'items.*.unit_id' => ['nullable', 'integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $hasCatalog = ! empty($item['catalog_item_id']);
                $hasName = isset($item['name']) && $item['name'] !== '';
                if ($hasCatalog === $hasName) {
                    $validator->errors()->add("items.$i.name", 'shopping.error.name_invalid');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target.*' => 'recipes.shopping.err_target',
            'list_id.*' => 'recipes.shopping.err_list',
            'name.*' => 'shopping.error.name_invalid',
            'store_id.*' => 'shopping.error.store_unknown',
            'items.required' => 'recipes.shopping.err_items',
            'items.array' => 'recipes.shopping.err_items',
            'items.min' => 'recipes.shopping.err_items',
            'items.max' => 'recipes.shopping.err_items',
            'items.*.array' => 'recipes.shopping.err_items',
            'items.*.catalog_item_id.*' => 'shopping.error.catalog_unknown',
            'items.*.name.*' => 'shopping.error.name_invalid',
            'items.*.quantity.required' => 'shopping.error.quantity',
            'items.*.quantity.regex' => 'shopping.error.quantity',
            'items.*.unit_id.*' => 'shopping.error.unit_unknown',
        ];
    }
}
