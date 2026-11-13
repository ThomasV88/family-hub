<?php

namespace App\Finance\Transactions;

use Carbon\CarbonImmutable;

/** Maps transaction rows to the Row and Panel arrays (explicit allowlist, amounts as strings). */
class TransactionPresenter
{
    /**
     * @param  array<string, string>  $accountNames  visible account id => name
     * @return array<string, mixed>
     */
    public function row(object $r, array $accountNames): array
    {
        $amount = (int) $r->amount_minor;
        $isTransfer = $r->kind === 'transfer' && $r->counterpart_account_id !== null;

        $transfer = null;
        if ($isTransfer) {
            $counterpart = (string) $r->counterpart_account_id;
            [$from, $to] = $amount < 0 ? [(string) $r->account_id, $counterpart] : [$counterpart, (string) $r->account_id];
            $transfer = [
                'fromId' => $from,
                'fromName' => $accountNames[$from] ?? null,
                'toId' => $to,
                'toName' => $accountNames[$to] ?? null,
            ];
        }

        return [
            'id' => (string) $r->id,
            'kind' => $isTransfer ? 'transfer' : ($amount > 0 ? 'income' : 'expense'),
            'amountMinor' => (string) ($isTransfer ? abs($amount) : $amount),
            'currency' => (string) $r->currency,
            'bookedOn' => substr((string) $r->booked_on, 0, 10),
            'description' => $r->description,
            'categoryId' => $r->category_id,
            'accountId' => (string) $r->account_id,
            'accountName' => $accountNames[(string) $r->account_id] ?? null,
            'transfer' => $transfer,
            'source' => (string) $r->source,
            'matchedRecurringName' => $r->matched_recurring_name ?? null,
            'createdAt' => CarbonImmutable::parse((string) $r->created_at)->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $accountNames
     * @return array<string, mixed>
     */
    public function panel(object $r, array $accountNames, bool $editable): array
    {
        return $this->row($r, $accountNames) + [
            'note' => $r->note,
            'editable' => $editable,
            'match' => ($r->matched_recurring_id ?? null) === null ? null : [
                'recurringId' => (string) $r->matched_recurring_id,
                'recurringName' => (string) $r->matched_recurring_name,
                'dueDate' => substr((string) $r->matched_due_date, 0, 10),
            ],
        ];
    }
}
