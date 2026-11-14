<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitRequest extends FormRequest
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
        return self::fieldRules();
    }

    /** @return array<string, mixed> the one rule set shared with the AI accept path */
    public static function fieldRules(): array
    {
        $today = now((string) config('family.timezone'))->toDateString();

        return [
            'visited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'doctor' => ['nullable', 'string', 'max:120', new ValidUtf8('health.error.load')],
            'specialty_key' => ['required', 'string', Rule::enum(Specialty::class)],
            'specialty_other' => ['required_if:specialty_key,other', 'prohibited_unless:specialty_key,other', 'nullable', 'string', 'max:80', new ValidUtf8('health.visit.err_specialty_other')],
            'diagnosis' => ['nullable', 'string', 'max:2000', new ValidUtf8('health.error.load')],
            'notes' => ['nullable', 'string', 'max:4000', new ValidUtf8('health.error.load')],
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
            'visited_on.required' => 'health.visit.err_date',
            'visited_on.date_format' => 'health.visit.err_date',
            'visited_on.before_or_equal' => 'health.visit.err_date',
            'specialty_key.required' => 'health.visit.err_specialty',
            'specialty_key.enum' => 'health.visit.err_specialty',
            'specialty_other.required_if' => 'health.visit.err_specialty_other',
            'specialty_other.max' => 'health.visit.err_specialty_other',
            'specialty_other.prohibited_unless' => 'health.visit.err_specialty',
            'doctor.max' => 'health.error.load',
            'diagnosis.max' => 'health.error.load',
            'notes.max' => 'health.error.load',
        ];
    }
}
