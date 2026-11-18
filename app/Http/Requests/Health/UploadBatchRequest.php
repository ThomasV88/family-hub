<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One request class for every batch route; the rules are chosen by the route name. authorize() runs before validation, so a
 * hidden, unknown or malformed person and a view-only member all get the one 403 and a validation error can never reveal that
 * a hidden id exists. The batch and item ids are resolved by the query layer (no route model binding).
 */
class UploadBatchRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $this->subject = app(SubjectQuery::class)->findVisible($viewer, (string) $this->route('person'), AccessLevel::Edit);

        return $this->subject !== null;
    }

    public function subject(): MedicalSubject
    {
        return $this->subject ?? abort(403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $notice = [
            'acknowledged' => ['sometimes', 'nullable', 'boolean'],
            'providers' => ['sometimes', 'nullable', 'array', 'max:5'],
            'providers.*' => ['string', 'in:codex,copilot'],
            'textNotice' => ['sometimes', 'nullable', 'boolean'],
        ];

        return match ($this->route()?->getName()) {
            'health.batches.store', 'health.batches.rescan' => $notice,
            'health.batches.current' => ['itemId' => ['required', 'string', 'max:64']],
            'health.batches.choices' => [
                'choices' => ['present', 'array', 'max:200'],
                'choices.*' => ['array'],
                'choices.*.decision' => ['required', 'in:approve,reject'],
                'choices.*.values' => ['sometimes', 'nullable', 'array', 'max:12'],
                'choices.*.values.*' => ['nullable', 'string', 'max:'.(int) config('medical.upload_batches.max_choice_value_chars')],
            ],
            'health.batches.adjustments' => [
                'brightness' => ['required', 'integer', 'between:50,150'],
                'contrast' => ['required', 'integer', 'between:50,200'],
                'bw' => ['required', 'boolean'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> lang keys, never user text */
    public function messages(): array
    {
        return [
            'choices.*' => 'health.error.load',
            'choices.*.*' => 'health.error.load',
            'choices.*.values.*' => 'health.error.load',
            'brightness.*' => 'health.error.load',
            'contrast.*' => 'health.error.load',
            'bw.*' => 'health.error.load',
            'itemId.*' => 'health.error.load',
            'providers.*' => 'health.error.load',
        ];
    }
}
