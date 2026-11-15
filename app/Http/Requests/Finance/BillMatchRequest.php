<?php

namespace App\Http\Requests\Finance;

use App\Finance\Queries\MatchQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Queries\TransactionQuery;
use App\Finance\Viewer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Match a chosen transaction to an occurrence. The occurrence is resolved before validation (404 for a hidden, unknown or
 * non-occurrence alike); every transaction failure (hidden, unknown, other account, matched elsewhere) has one message.
 */
class BillMatchRequest extends FormRequest
{
    private ?object $item = null;

    public function authorize(): bool
    {
        $viewer = Viewer::for($this->user());
        $id = $this->route('recurring');
        $date = $this->route('date');
        $recurring = app(RecurringQuery::class);
        abort_unless(is_string($id) && is_string($date) && $recurring->bill($viewer, $id, $date) !== null, 404);
        $this->item = $recurring->findVisible($viewer, $id) ?? abort(404);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['transaction_id' => ['required', 'string']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'transaction_id.required' => 'finance.error.transaction',
            'transaction_id.string' => 'finance.error.transaction',
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->item === null) {
                return;
            }
            $viewer = Viewer::for($this->user());
            $tx = app(TransactionQuery::class)->findVisible($viewer, (string) $this->input('transaction_id'));
            $ok = $tx !== null
                && (string) $tx->account_id === (string) $this->item->account_id
                && ($tx->kind === 'expense' || ($tx->kind === 'transfer' && $tx->amount_minor < 0));
            if ($ok) {
                $taken = app(MatchQuery::class)->matchOfTransaction($viewer, (string) $tx->id);
                $ok = $taken === null
                    || ($taken->recurring_id === (string) $this->item->id && substr((string) $taken->due_date, 0, 10) === $this->route('date'));
            }
            if (! $ok) {
                $v->errors()->add('transaction_id', 'finance.error.transaction');
            }
        }];
    }

    public function item(): object
    {
        return $this->item ?? abort(404);
    }

    public function dueDate(): string
    {
        return (string) $this->route('date');
    }

    public function transactionId(): string
    {
        return (string) $this->input('transaction_id');
    }
}
