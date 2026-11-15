<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\FormRequest;

class MarkVaccinationGivenRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    /** Authorize first (see VaccinationRequest): identical 403 for every person the member may not change. */
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
            'given_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now((string) config('family.timezone'))->toDateString()],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:given_on'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'given_on.required' => 'health.vaccination.err_given_on',
            'given_on.date_format' => 'health.vaccination.err_given_on',
            'given_on.before_or_equal' => 'health.vaccination.err_given_on',
            'next_due_on.date_format' => 'health.vaccination.err_next_due',
            'next_due_on.after_or_equal' => 'health.vaccination.err_next_due',
        ];
    }
}
