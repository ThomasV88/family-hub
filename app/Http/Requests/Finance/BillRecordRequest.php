<?php

namespace App\Http\Requests\Finance;

use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\CategoryQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Record the payment of an open occurrence: creates an expense on the item's account and matches it. Omitted fields take
 * the occurrence's values (expected amount, due date, recurring name and category).
 */
class BillRecordRequest extends FormRequest
{
    private ?object $item = null;

    /** @var array<string, mixed>|null */
    private ?array $bill = null;

    private ?int $minor = null;

    private ?string $categoryId = null;

    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $id = $this->route('recurring');
        $date = $this->route('date');
        abort_unless(is_string($id) && is_string($date), 404);
        $recurring = app(RecurringQuery::class);
        $this->bill = $recurring->bill($viewer, $id, $date) ?? abort(404);
        $this->item = $recurring->findVisible($viewer, $id) ?? abort(404);

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
        return [
            'amount' => ['sometimes', 'nullable', 'string'],
            'booked_on' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                    || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1900 || (int) $m[1] > 2200) {
                    $fail('finance.error.date');
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.string' => 'finance.error.amount',
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
        return [function (Validator $v) {
            if ($this->item === null || $this->bill === null) {
                return;
            }
            if ($this->bill['match'] !== null) {
                // An occurrence that is already paid is changed through match/unmatch, not recorded again.
                $v->errors()->add('amount', 'finance.error.tx_save');

                return;
            }
            $viewer = Viewer::for($this->user());
            $account = app(AccountQuery::class)->findVisible($viewer, (string) $this->item->account_id);
            $currency = $account === null ? null : Currency::tryFrom((string) $account->currency);
            if ($account === null || $currency === null) {
                $v->errors()->add('amount', 'finance.error.account');

                return;
            }

            if ($this->input('amount') === null) {
                $this->minor = (int) $this->item->expected_amount_minor;
            } elseif (! $v->errors()->has('amount')) {
                $this->minor = MoneyParser::manual((string) $this->input('amount'), $currency);
                if ($this->minor === null) {
                    $v->errors()->add('amount', 'finance.error.amount');
                }
            }

            $date = $this->bookedOn();
            if (! $v->errors()->has('booked_on') && $account->balance_as_of !== null && $date < $account->balance_as_of->format('Y-m-d')) {
                $v->errors()->add('booked_on', 'finance.error.date_before_open');
            }

            $categoryId = $this->has('category_id') ? $this->input('category_id') : $this->item->category_id;
            if ($categoryId !== null && ! $v->errors()->has('category_id')) {
                $category = app(CategoryQuery::class)->find((string) $categoryId);
                if ($category === null || $category->kind !== 'expense') {
                    $v->errors()->add('category_id', 'finance.error.category');
                } else {
                    $this->categoryId = (string) $category->id;
                }
            }
        }];
    }

    public function item(): object
    {
        return $this->item ?? abort(404);
    }

    public function bookedOn(): string
    {
        return is_string($this->input('booked_on')) ? $this->input('booked_on') : (string) $this->route('date');
    }

    public function dueDate(): string
    {
        return (string) $this->route('date');
    }

    /** @return array{minor: int, booked_on: string, description: string, category_id: ?string, note: ?string} */
    public function payment(): array
    {
        $description = $this->input('description');

        return [
            'minor' => $this->minor ?? abort(422),
            'booked_on' => $this->bookedOn(),
            'description' => is_string($description) ? $description : (string) $this->item()->name,
            'category_id' => $this->categoryId,
            'note' => is_string($this->input('note')) ? $this->input('note') : null,
        ];
    }
}
