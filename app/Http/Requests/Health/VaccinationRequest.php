<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class VaccinationRequest extends FormRequest
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
            'vaccine' => ['required', 'string', 'max:120', new ValidUtf8('health.vaccination.err_vaccine')],
            'given_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now((string) config('family.timezone'))->toDateString()],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:given_on'],
            'notes' => ['nullable', 'string', 'max:500', new ValidUtf8('health.error.load')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vaccine.required' => 'health.vaccination.err_vaccine',
            'vaccine.max' => 'health.vaccination.err_vaccine',
            'given_on.required' => 'health.vaccination.err_given_on',
            'given_on.date_format' => 'health.vaccination.err_given_on',
            'given_on.before_or_equal' => 'health.vaccination.err_given_on',
            'next_due_on.date_format' => 'health.vaccination.err_next_due',
            'next_due_on.after_or_equal' => 'health.vaccination.err_next_due',
            'notes.max' => 'health.error.load',
        ];
    }
}
