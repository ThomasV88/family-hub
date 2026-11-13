<?php

namespace App\Finance\Transactions;

use App\Finance\Queries\TransactionQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, edits and deletes transactions. Existing rows are changed only through TransactionQuery::updateVisible and
 * deleteVisible, and a transfer's other leg is touched only when its account is visible to the editor.
 */
class TransactionWriter
{
    public function __construct(private readonly TransactionQuery $transactions) {}

    public function createSingle(Viewer $viewer, FinanceAccount $account, string $kind, int $minor, string $date, string $description, ?string $categoryId, ?string $note): void
    {
        $this->insert($viewer, $account, $kind, $kind === 'income' ? $minor : -$minor, $date, $description, $categoryId, $note, null);
    }

    public function createTransfer(Viewer $viewer, FinanceAccount $from, FinanceAccount $to, int $minor, string $date, ?string $description, ?string $note): void
    {
        $transferId = (string) Str::uuid7();
        DB::transaction(function () use ($viewer, $from, $to, $minor, $date, $description, $note, $transferId) {
            $this->insert($viewer, $from, 'transfer', -$minor, $date, $description, null, $note, $transferId);
            $this->insert($viewer, $to, 'transfer', $minor, $date, $description, null, null, $transferId);
        });
    }

    /**
     * @param  array<string, mixed>  $changes  any of: magnitude (int), booked_on, description, category_id, note
     */
    public function update(Viewer $viewer, FinanceTransaction $leg, ?FinanceTransaction $counterpart, array $changes): void
    {
        DB::transaction(function () use ($viewer, $leg, $counterpart, $changes) {
            $this->applyChanges($viewer, $leg, $changes);
            if ($counterpart !== null) {
                // Both accounts are visible: amount, date and description stay equal on both legs.
                $this->applyChanges($viewer, $counterpart, array_intersect_key($changes, array_flip(['magnitude', 'booked_on', 'description'])));
            }
        });
    }

    public function delete(Viewer $viewer, FinanceTransaction $leg, ?FinanceTransaction $counterpart): void
    {
        $ids = [(string) $leg->id];
        if ($counterpart !== null) {
            $ids[] = (string) $counterpart->id;
        }
        DB::transaction(fn () => $this->transactions->deleteVisible($viewer, $ids));
    }

    /** @param  array<string, mixed>  $changes */
    private function applyChanges(Viewer $viewer, FinanceTransaction $row, array $changes): void
    {
        $attributes = [];
        if (array_key_exists('magnitude', $changes)) {
            $amount = ($row->amount_minor < 0 ? -1 : 1) * (int) $changes['magnitude'];
            if ($amount !== $row->amount_minor) {
                $attributes['amount_minor'] = $amount;
            }
        }
        foreach (['booked_on', 'description', 'category_id', 'note'] as $key) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }
            $current = $key === 'booked_on' ? $row->booked_on?->format('Y-m-d') : $row->{$key};
            if ($changes[$key] !== $current) {
                $attributes[$key] = $changes[$key];
            }
        }
        if ($attributes === []) {
            return;
        }
        $attributes['edited_at'] = now();
        $this->transactions->updateVisible($viewer, (string) $row->id, $attributes);
    }

    private function insert(Viewer $viewer, FinanceAccount $account, string $kind, int $signedMinor, string $date, ?string $description, ?string $categoryId, ?string $note, ?string $transferId): void
    {
        $row = new FinanceTransaction;
        $row->forceFill([
            'account_id' => $account->id,
            'currency' => $account->currency,
            'kind' => $kind,
            'amount_minor' => $signedMinor,
            'booked_on' => $date,
            'description' => $description,
            'note' => $note,
            'category_id' => $categoryId,
            'transfer_id' => $transferId,
            'source' => 'manual',
            'created_by' => $viewer->userId(),
        ])->save();
    }
}
