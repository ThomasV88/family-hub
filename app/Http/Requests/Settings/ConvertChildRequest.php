<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:admin and access:recent decide; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:254',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && User::query()->whereRaw('lower(email) = ?', [mb_strtolower($value)])->exists()) {
                        $fail('settings.members.convert.err_email_taken');
                    }
                },
            ],
            'locale' => ['required', 'string', Rule::in(['sk', 'pl', 'en'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'auth.error.email_invalid',
            'email.string' => 'auth.error.email_invalid',
            'email.email' => 'auth.error.email_invalid',
            'email.max' => 'auth.error.email_invalid',
        ];
    }
}
