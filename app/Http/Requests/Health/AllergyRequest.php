<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class AllergyRequest extends FormRequest
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

    /** @return array<string, mixed> 'unknown' is reserved for AI rows, so it is not accepted here */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /** @return array<string, mixed> the one rule set shared with the AI accept path */
    public static function fieldRules(): array
    {
        return [
            'substance' => ['required', 'string', 'max:120', new ValidUtf8('health.allergy.err_substance')],
            'reaction' => ['nullable', 'string', 'max:200', new ValidUtf8('health.error.load')],
            'severity' => ['required', 'string', 'in:mild,moderate,severe'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::fieldMessages();
    }

    /** @return array<string, string> */
    public static function fieldMessages(): array
    {
        return [
            'substance.required' => 'health.allergy.err_substance',
            'substance.max' => 'health.allergy.err_substance',
            'severity.required' => 'health.allergy.err_severity',
            'severity.in' => 'health.allergy.err_severity',
            'reaction.max' => 'health.error.load',
        ];
    }
}
