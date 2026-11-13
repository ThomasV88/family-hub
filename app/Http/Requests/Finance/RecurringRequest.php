<?php

namespace App\Http\Requests\Finance;

use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\CategoryQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Recurrence\RuleBuilder;
use App\Tasks\TaskRecurrence;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Create (POST) and edit (PATCH) of a recurring expense. Schedule mode only: a posted mode is ignored. The account is
 * resolved through AccountQuery::findVisible (one message for hidden and unknown ids) and is immutable on edit.
 */
class RecurringRequest extends FormRequest
{
    private const IMMUTABLE = ['account_id', 'kind'];

    private ?object $item = null;

    private ?FinanceAccount $account = null;

    /** @var array<string, mixed> */
    private array $changes = [];

    private function viewer(): Viewer
    {
        return Viewer::for($this->user());
    }

    private function isEdit(): bool
    {
        return $this->isMethod('PATCH');
    }

    public function authorize(): bool
    {
        if ($this->isEdit()) {
            $id = $this->route('recurring');
            $item = is_string($id) ? app(RecurringQuery::class)->findVisible($this->viewer(), $id) : null;
            abort_if($item === null, 404);
            $this->item = $item;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['name', 'amount', 'tolerance', 'text_hint', 'starts_on', 'ends_on'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $trimmed[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($trimmed);

        // Numeric strings from a form post become integers so the pattern allowlist can stay strict.
        $recurrence = $this->input('recurrence');
        if (is_array($recurrence)) {
            foreach (['interval', 'monthDay'] as $key) {
                if (isset($recurrence[$key]) && is_string($recurrence[$key]) && ctype_digit($recurrence[$key])) {
                    $recurrence[$key] = (int) $recurrence[$key];
                }
            }
            $this->merge(['recurrence' => $recurrence]);
        }
        $window = $this->input('window_days');
        if (is_string($window) && ctype_digit($window)) {
            $this->merge(['window_days' => (int) $window]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->isEdit() ? 'sometimes' : 'required';
        $date = function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! $this->isDate($value)) {
                $fail('finance.error.date');
            }
        };

        return [
            'name' => [$presence, 'string', 'max:120'],
            'amount' => [$presence, 'string'],
            'account_id' => ['sometimes', 'nullable'],
            'kind' => ['sometimes', 'nullable'],
            'category_id' => ['sometimes', 'nullable', 'string'],
            'starts_on' => [$presence, 'string', $date],
            'ends_on' => ['sometimes', 'nullable', 'string', $date],
            'recurrence' => [$presence, 'array'],
            'window_days' => ['sometimes', 'integer', Rule::in((array) config('finance.recurring.window_days_options'))],
            'tolerance' => ['sometimes', 'nullable', 'string'],
            'text_hint' => ['sometimes', 'nullable', 'string', 'min:4', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'finance.rec.err_name',
            'name.string' => 'finance.rec.err_name',
            'name.max' => 'finance.rec.err_name',
            'amount.required' => 'finance.error.amount',
            'amount.string' => 'finance.error.amount',
            'category_id.string' => 'finance.error.category',
            'starts_on.required' => 'finance.error.date',
            'starts_on.string' => 'finance.error.date',
            'ends_on.string' => 'finance.error.date',
            'recurrence.required' => 'todo.error.save_failed',
            'recurrence.array' => 'todo.error.save_failed',
            'window_days.integer' => 'finance.rec.hint_window',
            'window_days.in' => 'finance.rec.hint_window',
            'tolerance.string' => 'finance.rec.hint_tolerance',
            'text_hint.string' => 'finance.rec.hint_text',
            'text_hint.max' => 'finance.rec.hint_text',
            'text_hint.min' => 'finance.rec.hint_text',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [fn (Validator $v) => $this->isEdit() ? $this->checkEdit($v) : $this->checkCreate($v)];
    }

    private function checkCreate(Validator $v): void
    {
        $value = $this->input('account_id');
        $this->account = is_string($value) ? app(AccountQuery::class)->findVisible($this->viewer(), $value) : null;
        if ($this->account === null) {
            $v->errors()->add('account_id', 'finance.error.account');
        }

        $this->changes = $this->gather($v, $this->account?->currency, null);
    }

    private function checkEdit(Validator $v): void
    {
        $present = false;
        $data = $this->all();
        foreach (self::IMMUTABLE as $key) {
            if (array_key_exists($key, $data)) {
                $v->errors()->add($key, 'finance.error.immutable');
                $present = true;
            }
        }
        if ($present || $this->item === null) {
            return;
        }

        $this->changes = $this->gather($v, (string) $this->item->currency, $this->item);
    }

    /**
     * Validates the sent fields that need more than a type check and returns them as column values.
     *
     * @return array<string, mixed>
     */
    private function gather(Validator $v, ?string $currency, ?object $item): array
    {
        $out = [];
        if ($this->has('name') && ! $v->errors()->has('name')) {
            $out['name'] = (string) $this->input('name');
        }

        $currencyEnum = $currency !== null ? Currency::tryFrom($currency) : null;
        if ($this->has('amount') && ! $v->errors()->has('amount') && $currencyEnum !== null) {
            $minor = MoneyParser::manual((string) $this->input('amount'), $currencyEnum);
            if ($minor === null) {
                $v->errors()->add('amount', 'finance.error.amount');
            } else {
                $out['expected_amount_minor'] = $minor;
            }
        }

        if ($this->has('tolerance') && ! $v->errors()->has('tolerance') && $currencyEnum !== null) {
            $raw = $this->input('tolerance');
            if ($raw === null || preg_match('/^0+([.,]0*)?$/', (string) $raw) === 1) {
                $out['tolerance_minor'] = 0;
            } else {
                $minor = MoneyParser::manual((string) $raw, $currencyEnum);
                if ($minor === null) {
                    $v->errors()->add('tolerance', 'finance.rec.hint_tolerance');
                } else {
                    $out['tolerance_minor'] = $minor;
                }
            }
        }

        if ($this->has('category_id') && ! $v->errors()->has('category_id')) {
            $categoryId = $this->input('category_id');
            if ($categoryId === null) {
                $out['category_id'] = null;
            } else {
                $category = app(CategoryQuery::class)->find((string) $categoryId);
                if ($category === null || $category->kind !== 'expense') {
                    $v->errors()->add('category_id', 'finance.error.category');
                } else {
                    $out['category_id'] = $category->id;
                }
            }
        }

        foreach (['starts_on', 'ends_on'] as $key) {
            if ($this->has($key) && ! $v->errors()->has($key)) {
                $out[$key] = $this->input($key);
            }
        }
        $starts = $out['starts_on'] ?? ($item !== null ? substr((string) $item->starts_on, 0, 10) : null);
        $ends = array_key_exists('ends_on', $out) ? $out['ends_on'] : ($item !== null && $item->ends_on !== null ? substr((string) $item->ends_on, 0, 10) : null);
        if ($starts !== null && $ends !== null && $ends < $starts && ! $v->errors()->has('ends_on')) {
            $v->errors()->add('ends_on', 'finance.rec.err_ends');
        }

        if ($this->has('window_days') && ! $v->errors()->has('window_days')) {
            $out['window_days'] = (int) $this->input('window_days');
        }
        if ($this->has('text_hint') && ! $v->errors()->has('text_hint')) {
            $out['text_hint'] = $this->input('text_hint');
        }

        $rule = $this->rule($v, $starts, $item);
        if ($rule !== null) {
            $out['recurrence_rule'] = $rule;
        }

        return $out;
    }

    /** The canonical rule for the posted pattern; on edit without a pattern the stored one follows a changed first date. */
    private function rule(Validator $v, ?string $starts, ?object $item): ?string
    {
        if ($v->errors()->has('recurrence') || $v->errors()->has('starts_on') || $starts === null) {
            return null;
        }
        $recurrence = $this->input('recurrence');
        if (! is_array($recurrence)) {
            if ($item === null || $starts === substr((string) $item->starts_on, 0, 10)) {
                return null;
            }
            $recurrence = TaskRecurrence::stored((string) $item->recurrence_rule)?->toArray();
            if ($recurrence === null) {
                return null;
            }
        }

        $interval = $recurrence['interval'] ?? 1;
        if (! is_int($interval) || $interval < 1 || $interval > 99) {
            $v->errors()->add('recurrence.interval', 'todo.error.interval');

            return null;
        }
        if (($recurrence['kind'] ?? null) === 'weekly' && empty($recurrence['weekdays'])) {
            $v->errors()->add('recurrence.weekdays', 'todo.error.weekdays');

            return null;
        }
        try {
            return RuleBuilder::toRrule(TaskRecurrence::pattern($recurrence, $starts));
        } catch (InvalidArgumentException) {
            $v->errors()->add('recurrence', 'todo.error.save_failed');

            return null;
        }
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900 && (int) $m[1] <= 2200;
    }

    public function item(): object
    {
        return $this->item ?? abort(404);
    }

    public function account(): FinanceAccount
    {
        return $this->account ?? abort(422);
    }

    /**
     * The validated fields as column => value (only what a PATCH sent).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->changes;
    }
}
