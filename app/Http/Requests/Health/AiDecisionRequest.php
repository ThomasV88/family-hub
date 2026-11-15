<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\FormRequest;

class AiDecisionRequest extends FormRequest
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

    /**
     * Per-type keys are filtered and validated by the review query; here only the shape of the envelope is checked.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['values' => ['sometimes', 'nullable', 'array', 'max:12']];
    }
}
