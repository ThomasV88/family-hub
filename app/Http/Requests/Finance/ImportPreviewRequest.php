<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Mapping-step preview (JSON). The browser re-sends the file on each settings change; nothing is stored.
 * A refusal is answered as 422 {error: code} so the wizard can show the matching message.
 */
class ImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'encoding' => ['sometimes', 'nullable', 'string', 'max:20'],
            'delimiter' => ['sometimes', 'nullable', 'string', 'max:4'],
            'header_row' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $failed = $validator->failed();
        $error = 'binary';
        if (isset($failed['file']['Required'])) {
            $error = 'empty';
        } elseif (isset($failed['file']['Max']) || isset($failed['file']['Uploaded'])) {
            $error = 'too_large';
        } elseif (! isset($failed['file'])) {
            $error = 'decode_failed';
        }

        throw new HttpResponseException(response()->json(['error' => $error], 422, ['Cache-Control' => 'no-store']));
    }
}
