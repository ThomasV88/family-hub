<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\FormRequest;

class AiRunRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    /**
     * Runs before validation: a hidden, unknown or malformed person or document and a view-only member all get the one 403, so a
     * validation error can never reveal that a hidden id exists.
     */
    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $this->subject = app(SubjectQuery::class)->findVisible($viewer, (string) $this->route('person'), AccessLevel::Edit);

        if ($this->subject === null) {
            return false;
        }

        // The document too, so an invalid body cannot tell a hidden or unknown document from a visible one.
        return app(DocumentQuery::class)->find($viewer, $this->subject, (string) $this->route('document'), AccessLevel::Edit) !== null;
    }

    public function subject(): MedicalSubject
    {
        return $this->subject ?? abort(403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acknowledged' => ['sometimes', 'nullable', 'boolean'],
            // The provider keys the member saw in the notice dialog; the server compares them with the enabled set.
            'providers' => ['sometimes', 'nullable', 'array', 'max:5'],
            'providers.*' => ['string', 'in:codex,copilot'],
        ];
    }
}
