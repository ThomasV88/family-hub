<?php

namespace App\Http\Requests\Finance;

use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\BudgetQuery;
use App\Finance\Queries\CategoryQuery;
use App\Finance\Viewer;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST), change (PATCH) and remove (DELETE) of a budget limit. A budget id is resolved through
 * BudgetQuery::find, so one that is unknown or in a currency the viewer cannot see answers the same 404.
 */
class BudgetRequest extends FormRequest
{
    private const MAX_MINOR = 10000000000000;

    private ?FinanceBudget $budget = null;

    private ?FinanceCategory $category = null;

    private ?int $minor = null;

    private function viewer(): Viewer
    {
        return Viewer::for($this->user());
    }

    public function authorize(): bool
    {
        if (! $this->isMethod('POST')) {
            $id = $this->route('budget');
            $budget = is_string($id) ? app(BudgetQuery::class)->find($this->viewer(), $id) : null;
            abort_if($budget === null, 404);
            $this->budget = $budget;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['amount', 'from_month', 'currency'] as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }
        $this->merge($trimmed);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $remove = $this->isMethod('DELETE');

        return [
            'category_id' => $create ? ['required', 'string'] : ['prohibited'],
            'currency' => $create ? ['required', 'string'] : ['prohibited'],
            'amount' => $remove ? ['prohibited'] : ['required', 'string'],
            'from_month' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1
                    || (int) $m[1] < 1900 || (int) $m[1] > 2200) {
                    $fail('finance.error.month');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_id.required' => 'finance.error.category',
            'category_id.string' => 'finance.error.category',
            'category_id.prohibited' => 'finance.error.immutable',
            'currency.required' => 'finance.error.account',
            'currency.string' => 'finance.error.account',
            'currency.prohibited' => 'finance.error.immutable',
            'amount.required' => 'finance.budget.err_amount',
            'amount.string' => 'finance.budget.err_amount',
            'amount.prohibited' => 'finance.error.immutable',
            'from_month.required' => 'finance.error.month',
            'from_month.string' => 'finance.error.month',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            $currency = $this->budget !== null ? (string) $this->budget->currency : $this->checkCreate($v);
            if (! $this->isMethod('DELETE') && ! $v->errors()->has('amount')) {
                $this->checkAmount($v, $currency);
            }
        }];
    }

    private function checkCreate(Validator $v): ?string
    {
        $currency = $this->input('currency');
        $known = is_string($currency) && in_array($currency, app(AccountQuery::class)->currencies($this->viewer()), true)
            && Currency::tryFrom($currency) !== null;
        if (! $known && ! $v->errors()->has('currency')) {
            $v->errors()->add('currency', 'finance.error.account');
        }

        $id = $this->input('category_id');
        if (is_string($id) && ! $v->errors()->has('category_id')) {
            $category = app(CategoryQuery::class)->find($id);
            if ($category === null || $category->kind !== 'expense') {
                $v->errors()->add('category_id', 'finance.error.category');
            }
            $this->category = $category;
        }

        if ($known && $this->category !== null && ! $v->errors()->has('from_month')
            && app(BudgetQuery::class)->activeFor((string) $this->category->id, (string) $currency, (string) $this->input('from_month')) !== null) {
            $v->errors()->add('category_id', 'finance.budget.err_taken');
        }

        return $known ? (string) $currency : null;
    }

    private function checkAmount(Validator $v, ?string $currency): void
    {
        $enum = $currency !== null ? Currency::tryFrom($currency) : null;
        if ($enum === null) {
            return; // the currency error is already reported
        }
        $minor = MoneyParser::manual((string) $this->input('amount'), $enum);
        if ($minor === null || $minor < 1 || $minor > self::MAX_MINOR) {
            $v->errors()->add('amount', 'finance.budget.err_amount');

            return;
        }
        $this->minor = $minor;
    }

    public function budget(): FinanceBudget
    {
        return $this->budget ?? abort(404);
    }

    public function category(): FinanceCategory
    {
        return $this->category ?? abort(422);
    }

    public function currency(): string
    {
        return (string) $this->input('currency');
    }

    public function minor(): int
    {
        return $this->minor ?? abort(422);
    }

    public function fromMonth(): string
    {
        return (string) $this->input('from_month');
    }
}
