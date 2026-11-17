<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipeRequest extends FormRequest
{
    /** Vulgar fractions that cooks type or paste. */
    private const VULGAR = ['¼' => 0.25, '½' => 0.5, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * "0,5", "1/2", "1 1/2" and "½" become a decimal with at most two places (half up). Anything else only loses the
     * spaces at the ends and gets a dot for the comma; the rules then refuse what is not a plain number.
     */
    public static function normaliseQuantity(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        $text = trim((string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value));

        $glyphs = implode('', array_keys(self::VULGAR));
        if (preg_match('/^(?:(\d{1,4})\s*)?(['.$glyphs.'])$/u', $text, $m) === 1) {
            return self::decimal((int) $m[1] + self::VULGAR[$m[2]]);
        }
        if (preg_match('/^(?:(\d{1,4})\s+)?(\d{1,4})\s*\/\s*(\d{1,4})$/', $text, $m) === 1 && (int) $m[3] > 0) {
            return self::decimal((int) $m[1] + (int) $m[2] / (int) $m[3]);
        }

        return str_replace(',', '.', $text);
    }

    private static function decimal(float $value): string
    {
        return number_format(floor($value * 100 + 0.5 + 1e-9) / 100, 2, '.', '');
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('title'))) {
            $merge['title'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('title')));
        }
        $ingredients = $this->input('ingredients');
        if (is_array($ingredients)) {
            foreach ($ingredients as $i => $line) {
                if (is_array($line) && array_key_exists('quantity', $line)) {
                    $ingredients[$i]['quantity'] = self::normaliseQuantity($line['quantity']);
                }
            }
            $merge['ingredients'] = $ingredients;
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(int) config('recipes.title_max')],
            'description' => ['nullable', 'string', 'max:'.(int) config('recipes.description_max')],
            'servings' => ['nullable', 'integer', 'min:'.(int) config('recipes.servings_min'), 'max:'.(int) config('recipes.servings_max')],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('recipes.minutes_max')],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('recipes.minutes_max')],
            'source_url' => ['nullable', 'string', 'max:'.(int) config('recipes.source_url_max'), $this->httpUrl(...)],
            'visibility' => ['required', Rule::in(['private', 'shared'])],
            // A draft id from the import review; anything unusable simply saves the recipe as manual.
            'import_id' => ['nullable', 'string', 'max:64'],
            'client_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'categories' => ['nullable', 'array', 'max:'.(int) config('recipes.categories_max')],
            'categories.*' => ['nullable', 'string', 'max:'.(int) config('recipes.category_max')],
            'ingredients' => ['required', 'array', 'min:1', 'max:'.(int) config('recipes.ingredients_max')],
            'ingredients.*' => ['array'],
            ...$this->lineIdRules('ingredients'),
            'ingredients.*.name' => ['required', 'string', 'max:'.(int) config('recipes.ingredient_name_max')],
            'ingredients.*.quantity' => [
                'nullable', 'bail', 'regex:/^\d{1,4}(\.\d{1,2})?$/',
                fn (string $attribute, mixed $value, \Closure $fail) => (float) $value > 0 && (float) $value <= (float) config('recipes.quantity_max')
                    ? null
                    : $fail('recipes.error.quantity'),
            ],
            'ingredients.*.unit_id' => ['nullable', 'integer', 'exists:shopping_units,id'],
            'ingredients.*.note' => ['nullable', 'string', 'max:'.(int) config('recipes.ingredient_note_max')],
            'steps' => ['required', 'array', 'min:1', 'max:'.(int) config('recipes.steps_max')],
            'steps.*' => ['array'],
            ...$this->lineIdRules('steps'),
            'steps.*.body' => ['required', 'string', 'max:'.(int) config('recipes.step_max')],
        ];
    }

    /** Store ignores line ids; update allows them. @return array<string, mixed> */
    protected function lineIdRules(string $group): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.*' => 'recipes.error.title_invalid',
            'description.*' => 'recipes.error.description_invalid',
            'servings.*' => 'recipes.error.servings_invalid',
            'prep_minutes.*' => 'recipes.error.prep_minutes_invalid',
            'cook_minutes.*' => 'recipes.error.cook_minutes_invalid',
            'source_url.*' => 'recipes.error.source_url',
            'visibility.*' => 'recipes.error.visibility_invalid',
            'categories.max' => 'recipes.error.categories',
            'categories.*' => 'recipes.error.categories',
            'ingredients.required' => 'recipes.error.ingredients_required',
            'ingredients.array' => 'recipes.error.ingredients_required',
            'ingredients.min' => 'recipes.error.ingredients_required',
            'ingredients.max' => 'recipes.error.ingredients_count',
            'ingredients.*.array' => 'recipes.error.ingredient_invalid',
            'ingredients.*.id.*' => 'recipes.error.unknown_line',
            'ingredients.*.name.*' => 'recipes.error.ingredient_invalid',
            'ingredients.*.quantity.regex' => 'recipes.error.quantity',
            'ingredients.*.unit_id.*' => 'recipes.error.unit_unknown',
            'ingredients.*.note.*' => 'recipes.error.ingredient_note_invalid',
            'steps.required' => 'recipes.error.steps_required',
            'steps.array' => 'recipes.error.steps_required',
            'steps.min' => 'recipes.error.steps_required',
            'steps.max' => 'recipes.error.steps_count',
            'steps.*.array' => 'recipes.error.step_invalid',
            'steps.*.id.*' => 'recipes.error.unknown_line',
            'steps.*.body.*' => 'recipes.error.step_invalid',
        ];
    }

    private function httpUrl(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parts = parse_url((string) $value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            $fail('recipes.error.source_url');
        }
    }
}
