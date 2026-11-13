<?php

namespace App\Finance\Recurring;

use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits and deletes recurring expenses. Existing rows are changed only through RecurringQuery::updateVisible
 * and deleteVisible. A changed rule, first date or amount applies from today on: earlier unpaid dates stop showing.
 */
class RecurringWriter
{
    public function __construct(private readonly RecurringQuery $recurring) {}

    /** @param  array<string, mixed>  $data  column => value as built by RecurringRequest */
    public function create(Viewer $viewer, FinanceAccount $account, array $data): void
    {
        $row = new FinanceRecurring;
        $row->forceFill([
            'account_id' => $account->id,
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'expected_amount_minor' => $data['expected_amount_minor'],
            'recurrence_rule' => $data['recurrence_rule'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'schedule_effective_from' => $data['starts_on'],
            'tolerance_minor' => $data['tolerance_minor'] ?? 0,
            'window_days' => $data['window_days'] ?? (int) config('finance.recurring.default_window_days', 5),
            'text_hint' => $data['text_hint'] ?? null,
            'created_by' => $viewer->userId(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $changes  any of the editable columns
     */
    public function update(Viewer $viewer, object $item, array $changes): void
    {
        $attributes = [];
        foreach ($changes as $column => $value) {
            $current = in_array($column, ['starts_on', 'ends_on'], true)
                ? ($item->{$column} === null ? null : substr((string) $item->{$column}, 0, 10))
                : $item->{$column};
            if ($value !== $current) {
                $attributes[$column] = $value;
            }
        }
        if ($attributes === []) {
            return;
        }
        if (array_intersect_key($attributes, array_flip(['recurrence_rule', 'starts_on', 'expected_amount_minor'])) !== []) {
            $attributes['schedule_effective_from'] = now((string) config('family.timezone'))->toDateString();
        }

        DB::transaction(fn () => $this->recurring->updateVisible($viewer, (string) $item->id, $attributes));
    }

    public function delete(Viewer $viewer, string $id): void
    {
        DB::transaction(fn () => $this->recurring->deleteVisible($viewer, $id));
    }
}
