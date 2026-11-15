<?php

namespace App\Http\Requests\Finance;

use App\Finance\Queries\CategoryQuery;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Commit of a draft batch. The batch is resolved first: a hidden, foreign, unknown or non-draft batch is an identical
 * 404 before any input is looked at, and an already committed batch redirects to its page without validation.
 */
class ImportCommitRequest extends FormRequest
{
    private ?object $batch = null;

    /** @var list<string> */
    private array $include = [];

    /** @var array<string, string> */
    private array $categories = [];

    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $batch = app(ImportQuery::class)->findBatchForViewer($viewer, (string) $this->route('batch'));
        abort_if($batch === null, 404);
        if ($batch->status === 'committed') {
            throw new HttpResponseException(redirect()->route('finance.import.show', ['batch' => $batch->id], 303));
        }
        abort_if($batch->status !== 'draft', 404);
        $this->batch = $batch;

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'include' => ['required', 'array', 'min:1', 'max:'.max(1, (int) ($this->batch->row_count ?? 1))],
            'include.*' => ['required', 'string', 'uuid'],
            'categories' => ['sometimes', 'nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'include.required' => 'finance.imp.select_one',
            'include.min' => 'finance.imp.select_one',
            'include.array' => 'finance.imp.err_commit',
            'include.max' => 'finance.imp.err_commit',
            'include.*.required' => 'finance.imp.err_commit',
            'include.*.string' => 'finance.imp.err_commit',
            'include.*.uuid' => 'finance.imp.err_commit',
            'categories.array' => 'finance.imp.err_commit',
            'categories.*.string' => 'finance.imp.err_commit',
            'categories.*.uuid' => 'finance.imp.err_commit',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $viewer = Viewer::for($this->user());
            $rows = [];
            foreach (app(ImportQuery::class)->stagedRows($viewer, (string) $this->batch->id) as $row) {
                $rows[(string) $row->id] = $row;
            }

            $include = array_values(array_unique(array_map('strval', (array) $this->input('include'))));
            foreach ($include as $id) {
                if (! isset($rows[$id]) || $rows[$id]->state === 'invalid') {
                    $v->errors()->add('include', 'finance.imp.err_commit');

                    return;
                }
            }
            $this->include = $include;

            $kinds = [];
            foreach (app(CategoryQuery::class)->all() as $category) {
                $kinds[(string) $category->id] = (string) $category->kind;
            }
            foreach ((array) $this->input('categories', []) as $rowId => $categoryId) {
                $row = $rows[(string) $rowId] ?? null;
                if ($row !== null && is_string($categoryId) && ($kinds[$categoryId] ?? null) === $row->kind) {
                    $this->categories[(string) $rowId] = $categoryId;
                }
            }
        }];
    }

    public function batchId(): string
    {
        return (string) $this->batch->id;
    }

    /** @return list<string> */
    public function included(): array
    {
        return $this->include;
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        return $this->categories;
    }
}
