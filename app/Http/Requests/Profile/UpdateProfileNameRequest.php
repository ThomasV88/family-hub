<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Profile\ProfileAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize before validating so someone who may not edit this person gets 403 whatever the body contains.
        $target = User::query()->findOrFail((int) $this->route('user'));

        return $this->user() !== null && app(ProfileAccess::class)->canEdit($this->user(), $target);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            // Collapse every run of whitespace (including tabs, which the control-character rule would otherwise
            // catch only after the fact) to one space and trim.
            $this->merge(['name' => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $target = User::query()->findOrFail((int) $this->route('user'));
        $max = (int) config('profile.name_max');

        return [
            'name' => [
                'required', 'string',
                'not_regex:/[\p{Cc}\p{Cf}]/u',
                // The length limit only applies when the name changes, so an older, longer name never blocks a save.
                function (string $attribute, mixed $value, Closure $fail) use ($target, $max): void {
                    if (is_string($value) && $value !== $target->name && mb_strlen($value) > $max) {
                        $fail('profile.err_name_max');
                    }
                },
            ],
            'email' => ['prohibited'],
        ];
    }

    /** @return array<string, string> Keys the page translates itself, like the photo errors. */
    public function messages(): array
    {
        return [
            'name.required' => 'profile.err_name_required',
            'name.string' => 'profile.err_name_required',
            'name.not_regex' => 'profile.err_name_chars',
            'email.prohibited' => 'profile.err_email_locked',
        ];
    }
}
