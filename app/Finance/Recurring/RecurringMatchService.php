<?php

namespace App\Finance\Recurring;

use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\MatchQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Queries\TransactionQuery;
use App\Finance\TextNormalizer;
use App\Finance\Transactions\TransactionWriter;
use App\Finance\Viewer;
use App\Models\FinanceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Expected-vs-actual matching writes. Everything here works on one recurring item's own account and is read and written
 * through the membership scope, so a transaction or match of a hidden account is never touched.
 */
class RecurringMatchService
{
    /** Largest allowed window_days; bounds the transaction date range that is read. */
    private const MAX_WINDOW = 14;

    public function __construct(
        private readonly RecurringQuery $recurring,
        private readonly MatchQuery $matches,
        private readonly TransactionQuery $transactions,
        private readonly AccountQuery $accounts,
        private readonly Matcher $matcher,
    ) {}

    /**
     * Matches the given (just written, visible) transactions to open occurrences of their accounts.
     *
     * @param  list<string>  $transactionIds
     * @return int number of new automatic matches
     */
    public function matchTransactions(Viewer $viewer, array $transactionIds): int
    {
        $count = 0;
        foreach ($this->matches->visibleAccountsOf($viewer, $transactionIds) as $accountId) {
            $count += $this->matchAccount($viewer, $accountId, $transactionIds, null);
        }

        return $count;
    }

    /** Matches existing unmatched payments to the open occurrences of one (new or changed) recurring item. */
    public function backfill(Viewer $viewer, object $item): int
    {
        return $this->matchAccount($viewer, (string) $item->account_id, null, (string) $item->id);
    }

    /**
     * Ranked unmatched same-account transactions near an occurrence.
     *
     * @param  array<string, mixed>  $bill  an occurrence as built by RecurringQuery::bill
     * @return list<array{id: string, description: ?string, bookedOn: string, accountName: string, amountMinor: string}>
     */
    public function candidates(Viewer $viewer, array $bill): array
    {
        $item = $this->recurring->findVisible($viewer, (string) $bill['recurringId']);
        if ($item === null) {
            return [];
        }
        $days = (int) config('finance.recurring.manual_candidate_days', 60);
        $due = (string) $bill['dueDate'];
        $rows = $this->matches->unmatchedCandidates($viewer, (string) $item->account_id, $this->shift($due, -$days), $this->shift($due, $days));

        $byId = [];
        $candidates = [];
        foreach ($rows as $row) {
            $byId[(string) $row->id] = $row;
            $candidates[] = $this->candidate($row);
        }
        $ranked = $this->matcher->rankForManual($this->occurrence($item, $due, (int) $item->expected_amount_minor), $candidates, $days);

        return array_map(function (MatchCandidate $c) use ($byId): array {
            $row = $byId[$c->transactionId];

            return [
                'id' => $c->transactionId,
                'description' => $row->description,
                'bookedOn' => $c->bookedOn,
                'accountName' => (string) $row->account_name,
                'amountMinor' => (string) $c->amountMinor,
            ];
        }, $ranked);
    }

    /** Pairs an occurrence with a chosen transaction, replacing the occurrence's current match. */
    public function matchManually(Viewer $viewer, object $item, string $date, string $transactionId): void
    {
        DB::transaction(function () use ($viewer, $item, $date, $transactionId) {
            $tx = $this->transactions->findVisible($viewer, $transactionId);
            if ($tx === null || ! $this->eligible($tx, (string) $item->account_id)) {
                $this->rejectTransaction();
            }
            $taken = $this->matches->matchOfTransaction($viewer, $transactionId);
            if ($taken !== null) {
                if ($taken->recurring_id === (string) $item->id && substr((string) $taken->due_date, 0, 10) === $date) {
                    return;
                }
                $this->rejectTransaction();
            }

            $this->matches->deleteMatch($viewer, (string) $item->id, $date);
            if (! $this->matches->insertManual((string) $item->id, (string) $item->account_id, $date, $transactionId, (int) $item->expected_amount_minor, $viewer->userId())) {
                $this->rejectTransaction();
            }
        });
    }

    /** Removes the match of an occurrence and remembers the pair so automatic matching never re-pairs it. */
    public function unmatch(Viewer $viewer, object $item, string $date): void
    {
        DB::transaction(function () use ($viewer, $item, $date) {
            $match = $this->matches->matchFor($viewer, (string) $item->id, $date);
            if ($match === null) {
                return;
            }
            if ($this->matches->deleteMatch($viewer, (string) $item->id, $date) > 0) {
                $this->matches->insertRejection((string) $item->id, (string) $item->account_id, $date, (string) $match->transaction_id);
            }
        });
    }

    /**
     * Creates the payment on the item's account and matches it, in one database transaction.
     *
     * @param  array{minor: int, booked_on: string, description: string, category_id: ?string, note: ?string}  $validated
     */
    public function recordPayment(Viewer $viewer, object $item, string $date, array $validated): FinanceTransaction
    {
        return DB::transaction(function () use ($viewer, $item, $date, $validated): FinanceTransaction {
            $account = $this->accounts->findVisible($viewer, (string) $item->account_id) ?? abort(404);
            // Resolved lazily: the writer itself depends on this service.
            $tx = app(TransactionWriter::class)->createSingle(
                $viewer, $account, 'expense', $validated['minor'], $validated['booked_on'], $validated['description'],
                $validated['category_id'] ?? null, $validated['note'] ?? null, false,
            );
            $this->matches->deleteMatch($viewer, (string) $item->id, $date);
            if (! $this->matches->insertManual((string) $item->id, (string) $item->account_id, $date, (string) $tx->id, (int) $item->expected_amount_minor, $viewer->userId())) {
                $this->rejectTransaction();
            }

            return $tx;
        });
    }

    /**
     * Open (unmatched, inside their window) occurrences of one account's visible recurring items.
     *
     * @return array{0: array<string, object>, 1: list<Occurrence>} items by id, open occurrences
     */
    private function openOccurrences(Viewer $viewer, string $accountId, ?string $onlyRecurringId): array
    {
        $today = now((string) config('family.timezone'))->toDateString();
        $from = $this->shift($today, -(int) config('finance.recurring.overdue_lookback_days', 45));

        $items = [];
        foreach ($this->recurring->visible($viewer, $accountId) as $item) {
            if ($onlyRecurringId === null || (string) $item->id === $onlyRecurringId) {
                $items[(string) $item->id] = $item;
            }
        }
        if ($items === []) {
            return [[], []];
        }

        $open = [];
        foreach ($this->recurring->occurrences($viewer, $from, $this->shift($today, self::MAX_WINDOW), $accountId) as $occ) {
            $item = $items[$occ['recurringId']] ?? null;
            if ($item === null || $occ['match'] !== null || $occ['dueDate'] > $this->shift($today, (int) $item->window_days)) {
                continue;
            }
            $open[] = $this->occurrence($item, $occ['dueDate'], (int) $item->expected_amount_minor);
        }

        return [$items, $open];
    }

    /**
     * Which recurring item each staged import row would auto-match. Read-only: nothing is written.
     *
     * @param  list<object>  $rows  staged rows with id, booked_on, amount_minor, kind, description, state
     * @return array<string, string> row id => recurring item name
     */
    public function previewMatches(Viewer $viewer, string $accountId, array $rows): array
    {
        [$items, $open] = $this->openOccurrences($viewer, $accountId, null);
        if ($open === []) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if ($row->state !== 'new' || $row->amount_minor === null || (int) $row->amount_minor >= 0 || $row->kind !== 'expense') {
                continue;
            }
            $candidates[] = new MatchCandidate(
                (string) $row->id,
                $accountId,
                substr((string) $row->booked_on, 0, 10),
                (int) $row->amount_minor,
                'expense',
                TextNormalizer::normalize((string) ($row->description ?? '')),
            );
        }
        if ($candidates === []) {
            return [];
        }

        $names = [];
        foreach ($this->matcher->assign($open, $candidates, []) as $pair) {
            [$recurringId] = explode('.', $pair['occurrence'], 2);
            $names[$pair['transaction']] = (string) $items[$recurringId]->name;
        }

        return $names;
    }

    /**
     * @param  list<string>|null  $onlyTransactionIds  candidates restricted to these ids (null = every unmatched one)
     */
    private function matchAccount(Viewer $viewer, string $accountId, ?array $onlyTransactionIds, ?string $onlyRecurringId): int
    {
        [$items, $open] = $this->openOccurrences($viewer, $accountId, $onlyRecurringId);
        if ($open === []) {
            return 0;
        }

        $dates = array_map(fn (Occurrence $o) => $o->dueDate, $open);
        $rows = $this->matches->unmatchedCandidates(
            $viewer, $accountId, $this->shift(min($dates), -self::MAX_WINDOW), $this->shift(max($dates), self::MAX_WINDOW), $onlyTransactionIds,
        );
        if ($rows === []) {
            return 0;
        }

        $byId = [];
        $candidates = [];
        foreach ($rows as $row) {
            $byId[(string) $row->id] = $row;
            $candidates[] = $this->candidate($row);
        }

        $assigned = $this->matcher->assign($open, $candidates, $this->matches->rejectionPairs($viewer, array_keys($items)));
        $now = now();
        $insert = [];
        foreach ($assigned as $pair) {
            [$recurringId, $date] = explode('.', $pair['occurrence'], 2);
            $insert[] = [
                'id' => (string) Str::uuid7(),
                'recurring_id' => $recurringId,
                'account_id' => $accountId,
                'due_date' => $date,
                'transaction_id' => $pair['transaction'],
                'matched_by' => 'auto',
                'expected_amount_minor' => (int) $items[$recurringId]->expected_amount_minor,
                'created_by' => $viewer->userId(),
                'created_at' => $now,
            ];
        }

        return $this->matches->insertAuto($insert);
    }

    private function occurrence(object $item, string $date, int $expected): Occurrence
    {
        return new Occurrence(
            (string) $item->id,
            $date,
            $expected,
            (int) $item->tolerance_minor,
            (int) $item->window_days,
            (string) $item->account_id,
            $item->text_hint === null ? null : TextNormalizer::normalize((string) $item->text_hint),
        );
    }

    private function candidate(object $row): MatchCandidate
    {
        return new MatchCandidate(
            (string) $row->id,
            (string) $row->account_id,
            substr((string) $row->booked_on, 0, 10),
            (int) $row->amount_minor,
            (string) $row->kind,
            TextNormalizer::normalize((string) ($row->description ?? '')),
        );
    }

    private function eligible(FinanceTransaction $tx, string $accountId): bool
    {
        return (string) $tx->account_id === $accountId
            && ($tx->kind === 'expense' || ($tx->kind === 'transfer' && $tx->amount_minor < 0));
    }

    private function rejectTransaction(): never
    {
        throw ValidationException::withMessages(['transaction_id' => ['finance.error.transaction']]);
    }

    private function shift(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date.' '.sprintf('%+d', $days).' days'));
    }
}
