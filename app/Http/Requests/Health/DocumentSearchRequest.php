<?php

namespace App\Http\Requests\Health;

use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The search box. The person filter has no uuid rule on purpose: a malformed value must answer like a person without a
 * match (an empty result), never a 422 that tells the two apart.
 */
class DocumentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's access:module,health,view middleware is the gate; the viewer is built in the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:200', new ValidUtf8('health.error.load')],
            'person' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
