<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Match and rejection rows of recurring occurrences. Every read and delete carries the membership subquery; every
 * written row takes its account_id from the recurring item (the composite foreign keys enforce same-account pairs).
 */
class MatchQuery
{
    /**
     * Unmatched expense or negative-transfer transactions of one visible account booked in [from, to].
     *
     * @param  list<string>|null  $onlyIds  restrict to these transaction ids
     * @return list<object> id, account_id, booked_on, amount_minor, kind, description, account_name
     */
    public function unmatchedCandidates(Viewer $viewer, string $accountId, string $from, string $to, ?array $onlyIds = null): array
    {
        if ($onlyIds === []) {
            return [];
        }
        $query = Visibility::apply(
            DB::table('finance_transactions as t')->join('finance_accounts as a', 'a.id', '=', 't.account_id'),
            $viewer,
            't.account_id'
        )
            ->where('t.account_id', $accountId)
            ->where('t.amount_minor', '<', 0)
            ->whereIn('t.kind', ['expense', 'transfer'])
            ->where('t.booked_on', '>=', $from)
            ->where('t.booked_on', '<=', $to)
            ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('finance_recurring_matches as m')->whereColumn('m.transaction_id', 't.id'));
        if ($onlyIds !== null) {
            $query->whereIn('t.id', $onlyIds);
        }

        return $query->select('t.id', 't.account_id', 't.booked_on', 't.amount_minor', 't.kind', 't.description', 'a.name as account_name')
            ->orderBy('t.booked_on')->orderBy('t.id')
            ->get()->all();
    }

    /**
     * The visible accounts that own any of the given transactions; ids of hidden accounts yield nothing.
     *
     * @param  list<string>  $transactionIds
     * @return list<string>
     */
    public function visibleAccountsOf(Viewer $viewer, array $transactionIds): array
    {
        $ids = array_values(array_filter($transactionIds, fn (string $id) => Str::isUuid($id)));
        if ($ids === []) {
            return [];
        }

        return Visibility::apply(DB::table('finance_transactions as t'), $viewer, 't.account_id')
            ->whereIn('t.id', $ids)->distinct()->orderBy('t.account_id')->pluck('t.account_id')
            ->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @param  list<string>  $recurringIds
     * @return list<string> 'recurringId.dueDate|transactionId'
     */
    public function rejectionPairs(Viewer $viewer, array $recurringIds): array
    {
        if ($recurringIds === []) {
            return [];
        }
        $rows = Visibility::apply(DB::table('finance_match_rejections as r'), $viewer, 'r.account_id')
            ->whereIn('r.recurring_id', $recurringIds)
            ->select('r.recurring_id', 'r.due_date', 'r.transaction_id')
            ->get();

        return $rows->map(fn ($r) => $r->recurring_id.'.'.substr((string) $r->due_date, 0, 10).'|'.$r->transaction_id)->all();
    }

    /** The match of one occurrence, or null. */
    public function matchFor(Viewer $viewer, string $recurringId, string $date): ?object
    {
        return Visibility::apply(DB::table('finance_recurring_matches as m'), $viewer, 'm.account_id')
            ->where('m.recurring_id', $recurringId)
            ->where('m.due_date', $date)
            ->select('m.id', 'm.transaction_id', 'm.account_id', 'm.matched_by')
            ->first();
    }

    /** The match a transaction already belongs to, or null. */
    public function matchOfTransaction(Viewer $viewer, string $transactionId): ?object
    {
        if (! Str::isUuid($transactionId)) {
            return null;
        }

        return Visibility::apply(DB::table('finance_recurring_matches as m'), $viewer, 'm.account_id')
            ->where('m.transaction_id', $transactionId)
            ->select('m.recurring_id', 'm.due_date')
            ->first();
    }

    /**
     * Inserts automatic matches; a taken occurrence or transaction is skipped (ON CONFLICT DO NOTHING).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertAuto(array $rows): int
    {
        return $rows === [] ? 0 : (int) DB::table('finance_recurring_matches')->insertOrIgnore($rows);
    }

    /** A manual match; returns false when the transaction or the occurrence is already taken. */
    public function insertManual(string $recurringId, string $accountId, string $date, string $transactionId, int $expectedMinor, int $userId): bool
    {
        return DB::table('finance_recurring_matches')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'recurring_id' => $recurringId,
            'account_id' => $accountId,
            'due_date' => $date,
            'transaction_id' => $transactionId,
            'matched_by' => 'manual',
            'expected_amount_minor' => $expectedMinor,
            'created_by' => $userId,
            'created_at' => now(),
        ]) > 0;
    }

    /** Row lock on a visible recurring item for the rest of the transaction; false when it is not visible. */
    public function lockRecurring(Viewer $viewer, string $recurringId): bool
    {
        return Visibility::apply(DB::table('finance_recurring')->where('id', $recurringId), $viewer)->lockForUpdate()->value('id') !== null;
    }

    public function deleteMatch(Viewer $viewer, string $recurringId, string $date): int
    {
        return Visibility::apply(
            DB::table('finance_recurring_matches')->where('recurring_id', $recurringId)->where('due_date', $date),
            $viewer,
            'account_id'
        )->delete();
    }

    public function insertRejection(string $recurringId, string $accountId, string $date, string $transactionId): void
    {
        DB::table('finance_match_rejections')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'recurring_id' => $recurringId,
            'account_id' => $accountId,
            'due_date' => $date,
            'transaction_id' => $transactionId,
            'created_at' => now(),
        ]);
    }
}
