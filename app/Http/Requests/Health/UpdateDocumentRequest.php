<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    /**
     * Runs before validation: a hidden, unknown or malformed person and a view-only member all get the one 403, so a
     * validation error can never reveal that a hidden id exists.
     */
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
        return [
            'title' => ['required', 'string', 'max:120', new ValidUtf8('health.error.load')],
            'visit_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'health.error.load',
            'title.string' => 'health.error.load',
            'title.max' => 'health.error.load',
            'visit_id.max' => 'health.upload.err_visit',
            'visit_id.string' => 'health.upload.err_visit',
        ];
    }
}
