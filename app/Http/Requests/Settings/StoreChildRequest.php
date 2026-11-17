<?php

namespace App\Http\Requests\Settings;

use App\Rules\EligibleWishlistManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:admin and access:recent decide; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.(int) config('profile.name_max'), 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'parent_ids' => ['required', 'array', 'min:1', 'max:10'],
            'parent_ids.*' => ['integer', 'distinct', new EligibleWishlistManager],
        ];
    }

    /** @return array<string, string> Keys the dialog translates itself, like the profile errors. */
    public function messages(): array
    {
        return [
            'name.required' => 'profile.err_name_required',
            'name.string' => 'profile.err_name_required',
            'name.max' => 'profile.err_name_max',
            'name.not_regex' => 'profile.err_name_chars',
            'parent_ids.required' => 'settings.members.child.err_parent_required',
            'parent_ids.min' => 'settings.members.child.err_parent_required',
        ];
    }

    /** @return list<int> */
    public function parentIds(): array
    {
        return array_map('intval', $this->validated('parent_ids', []));
    }
}
