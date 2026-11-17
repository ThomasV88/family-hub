<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['name_en', 'name_sk', 'name_pl'] as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }
        $this->merge($trimmed);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = (int) config('shopping.name_max.unit');

        return [
            'name_en' => ['required', 'string', 'max:'.$max],
            'name_sk' => ['required', 'string', 'max:'.$max],
            'name_pl' => ['required', 'string', 'max:'.$max],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $m = [];
        foreach (['name_en', 'name_sk', 'name_pl'] as $key) {
            $m["{$key}.required"] = 'shopping.error.name_invalid';
            $m["{$key}.max"] = 'shopping.error.name_invalid';
        }

        return $m;
    }
}
