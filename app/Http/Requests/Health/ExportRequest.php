<?php

namespace App\Http\Requests\Health;

use App\Medical\Queries\ExportQuery;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    /**
     * Runs before validation: a hidden, unknown or malformed person and a view-only member all get the one 403, so a
     * validation error can never reveal that a hidden id exists.
     */
    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $this->subject = app(ExportQuery::class)->subject($viewer, (string) $this->route('person'));

        return $this->subject !== null;
    }

    public function subject(): MedicalSubject
    {
        return $this->subject ?? abort(403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'scope' => ['sometimes', Rule::in(['all', 'specialty'])],
            'specialty' => ['nullable', 'required_if:scope,specialty', Rule::enum(Specialty::class)],
            'include_unverified' => ['sometimes', 'boolean'],
        ];
        if ($this->routeIs('health.exports.download')) {
            $rules['format'] = ['sometimes', Rule::in(['json', 'md'])];
        }

        return $rules;
    }

    /** @return array{scope: string, specialty: ?string, include_unverified: bool, format: string} */
    public function options(): array
    {
        $scope = (string) $this->input('scope', 'all');

        return [
            'scope' => $scope,
            'specialty' => $scope === 'specialty' ? (string) $this->input('specialty') : null,
            'include_unverified' => $this->boolean('include_unverified'),
            'format' => (string) $this->input('format', 'json'),
        ];
    }
}
