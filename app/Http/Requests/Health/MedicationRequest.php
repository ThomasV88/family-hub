<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class MedicationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', new ValidUtf8('health.medication.err_name')],
            'dose' => ['nullable', 'string', 'max:120', new ValidUtf8('health.error.load')],
            'schedule' => ['nullable', 'string', 'max:160', new ValidUtf8('health.error.load')],
            'started_on' => ['nullable', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
            'notes' => ['nullable', 'string', 'max:1000', new ValidUtf8('health.error.load')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'health.medication.err_name',
            'name.max' => 'health.medication.err_name',
            'dose.max' => 'health.error.load',
            'schedule.max' => 'health.error.load',
            'notes.max' => 'health.error.load',
            'started_on.date_format' => 'health.error.load',
            'ended_on.date_format' => 'health.error.load',
            'ended_on.after_or_equal' => 'health.medication.err_end_before_start',
        ];
    }
}
