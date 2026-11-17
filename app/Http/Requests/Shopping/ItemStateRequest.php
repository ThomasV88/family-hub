<?php

namespace App\Http\Requests\Shopping;

use App\Shopping\ItemState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['state' => ['required', Rule::enum(ItemState::class)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['state.*' => 'shopping.error.state_invalid'];
    }
}
