<?php

namespace App\Http\Requests\Finance;

use App\Finance\Queries\CategoryQuery;
use App\Models\FinanceCategory;
use App\Rules\ValidUtf8;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST), rename (PATCH) and delete-with-move (DELETE) of a family category. Categories are a family-wide,
 * non-sensitive list, so ids resolve through CategoryQuery::find with no visibility rule.
 */
class CategoryRequest extends FormRequest
{
    private ?FinanceCategory $category = null;

    private ?FinanceCategory $target = null;

    public function authorize(): bool
    {
        if (! $this->isMethod('POST')) {
            $id = $this->route('category');
            $category = is_string($id) ? app(CategoryQuery::class)->find($id) : null;
            abort_if($category === null, 404);
            $this->category = $category;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['name', 'kind', 'move_to'] as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }
        $this->merge($trimmed);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['move_to' => ['required', 'string']];
        }

        return [
            'name' => ['required', 'string', 'max:40', new ValidUtf8('finance.cat.err_name')],
            'kind' => $this->isMethod('POST') ? ['required', 'in:expense,income'] : ['nullable'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'finance.cat.err_name',
            'name.string' => 'finance.cat.err_name',
            'name.max' => 'finance.cat.err_name',
            'kind.required' => 'finance.cat.err_name',
            'kind.in' => 'finance.cat.err_name',
            'move_to.required' => 'finance.cat.err_move',
            'move_to.string' => 'finance.cat.err_move',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            $categories = app(CategoryQuery::class);
            if ($this->isMethod('DELETE')) {
                if (! $v->errors()->has('move_to')) {
                    $target = $categories->find((string) $this->input('move_to'));
                    if ($target === null || $target->id === $this->category?->id || $target->kind !== $this->category?->kind) {
                        $v->errors()->add('move_to', 'finance.cat.err_move');
                    }
                    $this->target = $target;
                }

                return;
            }
            if ($v->errors()->has('name') || $v->errors()->has('kind')) {
                return;
            }
            $kind = $this->category !== null ? (string) $this->category->kind : (string) $this->input('kind');
            if ($categories->nameTaken($kind, (string) $this->input('name'), $this->category?->id)) {
                $v->errors()->add('name', 'finance.cat.err_taken');
            }
        }];
    }

    public function category(): FinanceCategory
    {
        return $this->category ?? abort(404);
    }

    public function target(): FinanceCategory
    {
        return $this->target ?? abort(422);
    }

    public function name(): string
    {
        return (string) $this->input('name');
    }

    public function kind(): string
    {
        return (string) $this->input('kind');
    }
}
