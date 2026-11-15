<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/** Exactly one string credential: `password` or `code`. Anything else (arrays, both, recovery codes) is a 422. */
class ReauthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $malformed = $this->has('recovery_code')
            || ($this->has('password') && $this->has('code'))
            || ($this->has('password') && ! is_string($this->input('password')))
            || ($this->has('code') && ! is_string($this->input('code')));

        abort_if($malformed, 422);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required_without:code', 'string', 'max:1000'],
            'code' => ['required_without:password', 'string', 'max:32'],
        ];
    }
}
