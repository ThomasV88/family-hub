<?php

namespace App\Http\Requests\Settings;

use App\Access\Preset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The access:admin route middleware decides; the request only validates.
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
            'email' => ['required', 'string', 'email', 'max:254'],
            'preset' => ['required', 'string', Rule::in(array_map(fn (Preset $p) => $p->value, Preset::cases()))],
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
