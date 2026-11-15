<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Passkey display name: 1-60 characters, no control characters (the package itself only checks max:255). */
class PasskeyNameRequest extends FormRequest
{
    public const ERROR = 'passkey.error.name';

    /** @return list<string> */
    public static function nameRules(): array
    {
        return ['required', 'string', 'min:1', 'max:60', 'regex:/^[^\p{C}]+$/u'];
    }

    /** @return array<string, string> */
    public static function nameMessages(): array
    {
        return ['name.required' => self::ERROR, 'name.string' => self::ERROR, 'name.min' => self::ERROR, 'name.max' => self::ERROR, 'name.regex' => self::ERROR];
    }

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

    public function rules(): array
    {
        return ['name' => self::nameRules()];
    }

    public function messages(): array
    {
        return self::nameMessages();
    }
}
