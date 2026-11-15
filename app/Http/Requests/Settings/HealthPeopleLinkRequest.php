<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Linked account and own-record switch. Whether the member can be linked is decided in the query with one error. */
class HealthPeopleLinkRequest extends FormRequest
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
            'user_id' => ['present', 'nullable', 'integer'],
            'self_access_enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.integer' => 'health.access.link_failed',
            'user_id.present' => 'health.access.link_failed',
            'self_access_enabled.required' => 'health.access.failed',
            'self_access_enabled.boolean' => 'health.access.failed',
        ];
    }

    public function userId(): ?int
    {
        $id = $this->validated('user_id');

        return $id === null ? null : (int) $id;
    }
}
