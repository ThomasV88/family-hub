<?php

namespace App\Http\Requests\Wishlist;

use App\Wishlist\Groups\GroupMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,wishlist,edit and GroupService (membership under lock) decide; the request only validates.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->isMethod('POST');
        $modes = array_map(fn (GroupMode $m) => $m->value, GroupMode::cases());

        $rules = [
            'name' => [$post ? 'required' : 'sometimes', 'string', 'min:1', 'max:'.config('wishlist.group_name_max')],
            'mode' => [$post ? 'required' : 'sometimes', Rule::in($modes)],
            'confirm_mode_change' => ['sometimes', 'boolean'],
        ];
        if ($post) {
            $rules['member_ids'] = ['sometimes', 'nullable', 'array', 'max:'.config('wishlist.group_max_members')];
            $rules['member_ids.*'] = ['integer', 'distinct'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'wishlist.group.error.name_required',
            'name.min' => 'wishlist.group.error.name_required',
            'name.max' => 'wishlist.group.error.name_too_long',
            'mode.required' => 'wishlist.group.error.mode_invalid',
            'mode.in' => 'wishlist.group.error.mode_invalid',
            'member_ids.max' => 'wishlist.group.error.too_many_members',
        ];
    }

    public function groupName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : null;
    }

    public function mode(): ?GroupMode
    {
        $mode = $this->validated('mode');

        return is_string($mode) ? GroupMode::from($mode) : null;
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_map('intval', $this->validated('member_ids') ?? []);
    }

    public function confirmed(): bool
    {
        return (bool) $this->validated('confirm_mode_change', false);
    }
}
