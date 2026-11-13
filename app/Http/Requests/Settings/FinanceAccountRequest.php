<?php

namespace App\Http\Requests\Settings;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\Queries\AccountAdminQuery;
use App\Finance\Viewer;
use App\Models\User;
use App\Rules\ValidUtf8;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates account metadata. Money-shaped fields (currency, opening balance, balance date) are accepted on update
 * only from an admin who is ticked on the account and has finance access; for every other admin they are
 * `prohibited` whatever the data looks like, so the answer never reveals anything about the account.
 */
class FinanceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // access:admin decides; the request only validates.
        return true;
    }

    private function isUpdate(): bool
    {
        return $this->route('account') !== null;
    }

    /** The admin's viewer for this account, or null when the admin is not ticked on it or lacks finance access. */
    public function seeingViewer(): ?Viewer
    {
        if (! $this->isUpdate()) {
            return null;
        }
        $user = $this->user();
        $level = app(AccessGate::class)->level($user, Module::Finance);
        $accountId = (string) $this->route('account');
        if ($level === AccessLevel::None || ! app(AccountAdminQuery::class)->isMember($accountId, $user->id)) {
            return null;
        }

        return new Viewer($user, $level);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:60', new ValidUtf8('finance.acc.err_save')],
            'type' => ['required', 'in:bank,cash,savings,card'],
            'member_ids' => ['present', 'array', 'max:50'],
            'member_ids.*' => ['integer', 'distinct', $this->eligibleMember()],
        ];

        if (! $this->isUpdate()) {
            return $rules + [
                'currency' => ['required', 'in:'.implode(',', Currency::codes())],
                'opening_balance' => ['nullable', 'string', 'max:40'],
                'balance_as_of' => ['required', 'date_format:Y-m-d'],
            ];
        }

        if ($this->seeingViewer() === null) {
            return $rules + ['currency' => ['prohibited'], 'opening_balance' => ['prohibited'], 'balance_as_of' => ['prohibited']];
        }

        return $rules + [
            'currency' => ['sometimes', 'in:'.implode(',', Currency::codes())],
            'opening_balance' => ['sometimes', 'nullable', 'string', 'max:40'],
            'balance_as_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /** Members must exist and hold finance view or edit. */
    private function eligibleMember(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = is_int($value) || ctype_digit((string) $value) ? User::query()->find((int) $value) : null;
            if ($user === null || app(AccessGate::class)->level($user, Module::Finance) === AccessLevel::None) {
                $fail('finance.error.members');
            }
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'finance.acc.err_name',
            'name.max' => 'finance.acc.err_name',
            'name.string' => 'finance.acc.err_name',
            'type.required' => 'finance.acc.err_save',
            'type.in' => 'finance.acc.err_save',
            'currency.required' => 'finance.acc.err_save',
            'currency.in' => 'finance.acc.err_save',
            'currency.prohibited' => 'finance.acc.err_save',
            'opening_balance.prohibited' => 'finance.acc.err_save',
            'balance_as_of.prohibited' => 'finance.acc.err_save',
            'opening_balance.string' => 'finance.error.amount',
            'opening_balance.max' => 'finance.error.amount',
            'balance_as_of.required' => 'finance.error.date',
            'balance_as_of.date_format' => 'finance.error.date',
            'member_ids.present' => 'finance.error.members',
            'member_ids.array' => 'finance.error.members',
            'member_ids.max' => 'finance.error.members',
            'member_ids.*.integer' => 'finance.error.members',
            'member_ids.*.distinct' => 'finance.error.members',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkOpening($validator);
        }];
    }

    private function checkOpening(Validator $validator): void
    {
        $query = app(AccountAdminQuery::class);
        $accountId = (string) $this->route('account');

        if ($this->isUpdate()) {
            $viewer = $this->seeingViewer();
            if ($viewer === null) {
                return;
            }
            $account = $query->find($accountId);
            if ($account === null) {
                return;
            }
            $currency = (string) $this->input('currency', $account->currency);
            if ($this->has('currency') && $currency !== $account->currency
                && ($query->hasTransactions($viewer, $accountId) || $query->hasDependents($viewer, $accountId))) {
                $validator->errors()->add('currency', 'finance.acc.currency_locked');
            }
            if ($this->has('balance_as_of') && (string) $this->input('balance_as_of') !== (string) ($query->opening($viewer, $accountId)['asOf'] ?? '')
                && $query->hasDependents($viewer, $accountId)) {
                $validator->errors()->add('balance_as_of', 'finance.acc.opening_locked');
            }
            if ($this->has('balance_as_of')) {
                $earliest = $query->earliestBookedOn($viewer, $accountId);
                if ($earliest !== null && (string) $this->input('balance_as_of') > $earliest) {
                    $validator->errors()->add('balance_as_of', 'finance.error.date_before_open');
                }
            }
        } else {
            $currency = (string) $this->input('currency');
        }

        if ($this->filled('opening_balance') && $this->openingMinor(Currency::from($currency)) === null) {
            $validator->errors()->add('opening_balance', 'finance.error.amount');
        }
    }

    /** Blank is zero (the parser rejects a typed zero because amounts must be non-zero; an opening balance may be zero). */
    public function openingMinor(Currency $currency): ?int
    {
        $raw = (string) $this->input('opening_balance', '');
        if (trim($raw) === '') {
            return 0;
        }
        if (preg_match('/^\s*[-\x{2212}]?\s*0+(?:[.,]0+)?\s*$/u', $raw) === 1) {
            return 0;
        }

        return MoneyParser::manual($raw, $currency, allowNegative: true);
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_values(array_map('intval', $this->validated('member_ids', [])));
    }
}
