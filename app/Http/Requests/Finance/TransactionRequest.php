<?php

namespace App\Http\Requests\Finance;

use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\CategoryQuery;
use App\Finance\Queries\TransactionQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST) and edit (PATCH) of a transaction. Every account id is resolved through AccountQuery::findVisible with
 * one message for hidden and nonexistent ids; the resolved models are exposed through accessors.
 */
class TransactionRequest extends FormRequest
{
    private const IMMUTABLE = ['account_id', 'kind', 'from_account_id', 'to_account_id'];

    private ?FinanceTransaction $leg = null;

    private ?FinanceTransaction $counterpart = null;

    private ?FinanceAccount $account = null;

    private ?FinanceAccount $from = null;

    private ?FinanceAccount $to = null;

    private ?FinanceCategory $category = null;

    private ?int $magnitude = null;

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
            $id = $this->route('transaction');
            $leg = is_string($id) ? app(TransactionQuery::class)->findVisible($this->viewer(), $id) : null;
            abort_if($leg === null, 404);
            $this->leg = $leg;
            $this->counterpart = app(TransactionQuery::class)->visibleCounterpart($this->viewer(), $leg);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['amount', 'booked_on', 'description', 'note'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $trimmed[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($trimmed);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->isEdit() ? 'sometimes' : 'required';

        return [
            'kind' => $this->isEdit() ? ['sometimes'] : ['required', 'string', 'in:income,expense,transfer'],
            'amount' => [$presence, 'string'],
            'booked_on' => [$presence, 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                    || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1900 || (int) $m[1] > 2200) {
                    $fail('finance.error.date');
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'nullable', 'string'],
            'account_id' => ['sometimes', 'nullable'],
            'from_account_id' => ['sometimes', 'nullable'],
            'to_account_id' => ['sometimes', 'nullable'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.required' => 'finance.error.tx_save',
            'kind.string' => 'finance.error.tx_save',
            'kind.in' => 'finance.error.tx_save',
            'amount.required' => 'finance.error.amount',
            'amount.string' => 'finance.error.amount',
            'booked_on.required' => 'finance.error.date',
            'booked_on.string' => 'finance.error.date',
            'description.string' => 'finance.error.description',
            'description.max' => 'finance.error.description',
            'note.string' => 'finance.error.tx_save',
            'note.max' => 'finance.error.tx_save',
            'category_id.string' => 'finance.error.category',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [fn (Validator $v) => $this->isEdit() ? $this->checkEdit($v) : $this->checkCreate($v)];
    }

    private function checkCreate(Validator $v): void
    {
        $kind = $this->input('kind');
        if (! in_array($kind, ['income', 'expense', 'transfer'], true)) {
            return;
        }

        $currency = null;
        if ($kind === 'transfer') {
            $this->from = $this->resolveAccount($v, 'from_account_id');
            $this->to = $this->resolveAccount($v, 'to_account_id');
            if ($this->from !== null && $this->to !== null) {
                if ($this->from->id === $this->to->id) {
                    $v->errors()->add('to_account_id', 'finance.error.transfer_same');
                } elseif ($this->from->currency !== $this->to->currency) {
                    $v->errors()->add('to_account_id', 'finance.error.transfer_currency');
                }
            }
            $currency = $this->from?->currency;
            $accounts = [$this->from, $this->to];
        } else {
            $this->account = $this->resolveAccount($v, 'account_id');
            $currency = $this->account?->currency;
            $accounts = [$this->account];
        }

        $this->checkAmount($v, $currency);
        $date = $this->input('booked_on');
        foreach ($accounts as $account) {
            if ($account !== null && ! $v->errors()->has('booked_on')) {
                $this->checkOpening($v, $date, $account);
            }
        }

        $categoryId = $this->input('category_id');
        if ($categoryId !== null && ! $v->errors()->has('category_id')) {
            $category = $kind === 'transfer' ? null : app(CategoryQuery::class)->find((string) $categoryId);
            if ($category === null || $category->kind !== $kind) {
                $v->errors()->add('category_id', 'finance.error.category');
            }
            $this->category = $category;
        }

        if ($kind !== 'transfer' && $this->input('description') === null && ! $v->errors()->has('description')) {
            $v->errors()->add('description', 'finance.error.description');
        }
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
        if ($present || $this->leg === null) {
            return;
        }

        if ($this->has('amount')) {
            $this->checkAmount($v, $this->leg->currency);
        }

        if ($this->has('booked_on') && ! $v->errors()->has('booked_on')) {
            $date = (string) $this->input('booked_on');
            $viewer = $this->viewer();
            $own = app(AccountQuery::class)->findVisible($viewer, (string) $this->leg->account_id);
            if ($own !== null && $date !== $this->leg->booked_on?->format('Y-m-d')) {
                $this->checkOpening($v, $date, $own);
            }
            if ($this->counterpart !== null && $date !== $this->counterpart->booked_on?->format('Y-m-d')) {
                $other = app(AccountQuery::class)->findVisible($viewer, (string) $this->counterpart->account_id);
                if ($other !== null) {
                    $this->checkOpening($v, $date, $other);
                }
            }
        }

        // A leg whose counterpart is hidden is an ordinary entry: it needs a description and a category of its sign.
        if ($this->has('description') && $this->input('description') === null && $this->counterpart === null && ! $v->errors()->has('description')) {
            $v->errors()->add('description', 'finance.error.description');
        }
        if ($this->input('category_id') !== null && ! $v->errors()->has('category_id')) {
            $kind = $this->leg->amount_minor > 0 ? 'income' : 'expense';
            $category = app(CategoryQuery::class)->find((string) $this->input('category_id'));
            if ($category === null || $category->kind !== $kind) {
                $v->errors()->add('category_id', 'finance.error.category');
            }
            $this->category = $category;
        }
    }

    private function resolveAccount(Validator $v, string $key): ?FinanceAccount
    {
        $value = $this->input($key);
        $account = is_string($value) ? app(AccountQuery::class)->findVisible($this->viewer(), $value) : null;
        if ($account === null) {
            $v->errors()->add($key, 'finance.error.account');
        }

        return $account;
    }

    private function checkAmount(Validator $v, ?string $currency): void
    {
        if ($v->errors()->has('amount')) {
            return;
        }
        $currencyEnum = $currency !== null ? Currency::tryFrom($currency) : null;
        if ($currencyEnum === null) {
            return; // the account error is already reported
        }
        $minor = MoneyParser::manual((string) $this->input('amount'), $currencyEnum);
        if ($minor === null) {
            $v->errors()->add('amount', 'finance.error.amount');

            return;
        }
        $this->magnitude = $minor;
    }

    private function checkOpening(Validator $v, mixed $date, FinanceAccount $account): void
    {
        if (is_string($date) && $account->balance_as_of !== null && $date < $account->balance_as_of->format('Y-m-d')) {
            $v->errors()->add('booked_on', 'finance.error.date_before_open');
        }
    }

    public function leg(): FinanceTransaction
    {
        return $this->leg ?? abort(404);
    }

    public function counterpart(): ?FinanceTransaction
    {
        return $this->counterpart;
    }

    public function account(): FinanceAccount
    {
        return $this->account ?? abort(422);
    }

    public function fromAccount(): FinanceAccount
    {
        return $this->from ?? abort(422);
    }

    public function toAccount(): FinanceAccount
    {
        return $this->to ?? abort(422);
    }

    public function category(): ?FinanceCategory
    {
        return $this->category;
    }

    public function magnitude(): int
    {
        return $this->magnitude ?? abort(422);
    }

    /**
     * The fields a PATCH actually sent, normalised for TransactionWriter::update.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('amount')) {
            $changes['magnitude'] = $this->magnitude();
        }
        foreach (['booked_on', 'description', 'note'] as $key) {
            if ($this->has($key)) {
                $changes[$key] = $this->input($key);
            }
        }
        if ($this->has('category_id')) {
            $changes['category_id'] = $this->category?->id;
        }

        return $changes;
    }
}
