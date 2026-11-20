<?php

namespace App\Http\Requests\Wishlist;

use Illuminate\Foundation\Http\FormRequest;

class GroupMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:module,wishlist,edit and GroupService (membership under lock) decide; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_ids' => ['required', 'array', 'min:1', 'max:'.config('wishlist.group_max_members')],
            'member_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'member_ids.required' => 'wishlist.group.error.self_required',
            'member_ids.min' => 'wishlist.group.error.self_required',
            'member_ids.max' => 'wishlist.group.error.too_many_members',
        ];
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_map('intval', $this->validated('member_ids'));
    }
}
