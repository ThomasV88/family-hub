<?php

namespace App\Http\Requests;

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'password' => $this->passwordRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'invite.accept.error.name_required',
            'name.max' => 'invite.accept.error.name_required',
            'password.required' => 'invite.accept.error.password_short',
            'password.min' => 'invite.accept.error.password_short',
            'password.confirmed' => 'invite.accept.error.password_mismatch',
            'password.uncompromised' => 'auth.error.password_compromised',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
