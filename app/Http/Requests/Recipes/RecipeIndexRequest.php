<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'ingredient' => ['nullable', 'string', 'max:100'],
            'categories' => ['nullable', 'array', 'max:10'],
            'categories.*' => ['string', 'max:90'],
            'author' => ['nullable', 'integer'],
            'show' => ['nullable', Rule::in(['all', 'mine', 'shared'])],
            'favourites' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array{q: string, ingredient: string, categories: list<string>, author: int|null, show: string, favourites: bool} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'q' => trim((string) ($data['q'] ?? '')),
            'ingredient' => trim((string) ($data['ingredient'] ?? '')),
            'categories' => array_values(array_map('strval', (array) ($data['categories'] ?? []))),
            'author' => isset($data['author']) ? (int) $data['author'] : null,
            'show' => (string) ($data['show'] ?? 'all'),
            'favourites' => (bool) ($data['favourites'] ?? false),
        ];
    }

    public function page(): int
    {
        return max(1, (int) ($this->validated()['page'] ?? 1));
    }
}
