<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWishlistManagersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:admin decides; the request only validates.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'manager_ids' => ['present', 'array', 'max:10'],
            'manager_ids.*' => [
                'integer', 'distinct', Rule::exists('users', 'id'),
                Rule::notIn([(int) $this->route('user')]),
            ],
        ];
    }

    /** @return list<int> */
    public function managerIds(): array
    {
        return array_map('intval', $this->validated('manager_ids', []));
    }
}
