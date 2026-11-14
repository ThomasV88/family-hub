<?php

namespace App\Http\Requests\Health;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    private ?MedicalSubject $subject = null;

    /** Runs before validation: hidden, unknown, malformed and view-only all get the one 403 and no file is touched. */
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
            'file' => ['required', 'file', 'max:'.intdiv((int) config('medical.document_max_bytes'), 1024)],
            'title' => ['nullable', 'string', 'max:120', new ValidUtf8('health.error.load')],
            'visit_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'health.upload.err_read',
            'file.file' => 'health.upload.err_read',
            'file.uploaded' => 'health.upload.err_size',
            'file.max' => 'health.upload.err_size',
            'title.max' => 'health.error.load',
            'visit_id.max' => 'health.upload.err_visit',
        ];
    }

    /**
     * The editable default title: the client base name without extension, control characters stripped, at most 120
     * characters. It is never stored as a file name, logged or used in a path.
     */
    public function defaultTitle(): string
    {
        $name = $this->file('file')?->getClientOriginalName() ?? '';
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $name = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return mb_substr($name !== '' ? $name : 'Document', 0, 120);
    }
}
