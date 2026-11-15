<?php

namespace App\Finance\Queries;

use App\Finance\Recurring\BillStatus;
use App\Finance\Recurring\MatchCandidate;
use App\Finance\Recurring\Occurrence;
use App\Finance\Recurring\OccurrenceWindow;
use App\Finance\Recurring\StatusResolver;
use App\Finance\TextNormalizer;
use App\Finance\Viewer;
use App\Finance\Visibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recurring expenses and their derived bill statuses. Every method is scoped to the viewer's accounts; a status is
 * never stored, it comes from the item, the sparse match rows and today's date in the family time zone.
 *
 * Occ (array): key, recurringId, name, dueDate, expectedMinor, currency, accountId, accountName, categoryId, status,
 * match (null | transactionId, description, bookedOn, amountMinor, accountName, differenceMinor, matchedBy).
 */
class RecurringQuery
{
    public function __construct(
        private readonly AccountQuery $accounts,
        private readonly OccurrenceWindow $window,
        private readonly StatusResolver $resolver,
    ) {}

    /** @return list<object> visible items with account_name and currency, by name */
    public function visible(Viewer $viewer, ?string $accountId = null): array
    {
        $query = $this->base($viewer);
        if ($accountId !== null) {
            $query->where('r.account_id', $accountId);
        }

        return $query->orderByRaw('lower(r.name)')->orderBy('r.id')->get()->all();
    }

    public function findVisible(Viewer $viewer, string $id): ?object
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return $this->base($viewer)->where('r.id', $id)->first();
    }

    /**
     * Occurrences with a due date in [from, to] (Y-m-d, inclusive), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function occurrences(Viewer $viewer, string $from, string $to, ?string $accountId = null): array
    {
        return $this->build($viewer, $from, $to, $accountId, null);
    }

    /** @return list<array{date: string, occurrences: list<array<string, mixed>>}> one entry per day of the month */
    public function calendar(Viewer $viewer, string $month, ?string $accountId = null): array
    {
        [$first, $last] = $this->monthBounds($month);
        $byDay = [];
        foreach ($this->occurrences($viewer, $first, $last, $accountId) as $occ) {
            $byDay[$occ['dueDate']][] = $occ;
        }

        $days = [];
        for ($d = $first; $d <= $last; $d = date('Y-m-d', (int) strtotime($d.' +1 day'))) {
            $days[] = ['date' => $d, 'occurrences' => $byDay[$d] ?? []];
        }

        return $days;
    }

    /**
     * Overdue items (oldest first, within the lookback) and everything due in the next 30 days.
     *
     * @return array{overdue: list<array<string, mixed>>, next30: list<array<string, mixed>>}
     */
    public function upcoming(Viewer $viewer, ?string $accountId = null): array
    {
        $today = $this->today();
        $lookback = (int) config('finance.recurring.overdue_lookback_days', 45);
        $past = $this->occurrences($viewer, $this->shift($today, -$lookback), $this->shift($today, -1), $accountId);

        return [
            'overdue' => array_values(array_filter($past, fn (array $o) => $o['status'] === BillStatus::Overdue->value)),
            'next30' => $this->occurrences($viewer, $today, $this->shift($today, 30), $accountId),
        ];
    }

    /**
     * Unpaid (upcoming and overdue) occurrences of a month per currency of the visible scope, exact integer sums.
     * Blocks follow AccountQuery::currencies order; a currency without unpaid items still gets a zero block.
     *
     * @return list<array{currency: string, sumMinor: string, count: int, overdueCount: int, next: list<array<string, mixed>>}>
     */
    public function stillToPay(Viewer $viewer, string $month, ?string $accountId = null): array
    {
        [$first, $last] = $this->monthBounds($month);
        $currencies = $this->accounts->currencies($viewer);
        if ($accountId !== null) {
            $account = $this->accounts->findVisible($viewer, $accountId);
            $currencies = $account === null ? [] : [(string) $account->currency];
        }

        $blocks = [];
        foreach ($currencies as $currency) {
            $blocks[$currency] = ['sum' => 0, 'count' => 0, 'overdue' => 0, 'next' => []];
        }
        foreach ($this->occurrences($viewer, $first, $last, $accountId) as $occ) {
            if (! in_array($occ['status'], [BillStatus::Upcoming->value, BillStatus::Overdue->value], true)) {
                continue;
            }
            $currency = $occ['currency'];
            if (! isset($blocks[$currency])) {
                continue;
            }
            $blocks[$currency]['sum'] += (int) $occ['expectedMinor'];
            $blocks[$currency]['count']++;
            if ($occ['status'] === BillStatus::Overdue->value) {
                $blocks[$currency]['overdue']++;
            }
            if (count($blocks[$currency]['next']) < 3) {
                $blocks[$currency]['next'][] = $occ;
            }
        }

        $out = [];
        foreach ($blocks as $currency => $b) {
            $out[] = [
                'currency' => (string) $currency,
                'sumMinor' => (string) $b['sum'],
                'count' => $b['count'],
                'overdueCount' => $b['overdue'],
                'next' => $b['next'],
            ];
        }

        return $out;
    }

    /** One occurrence of a visible item, or null when the item is hidden or unknown or the date is not one of its due dates. */
    public function bill(Viewer $viewer, string $recurringId, string $date): ?array
    {
        if (! Str::isUuid($recurringId) || ! $this->isDate($date)) {
            return null;
        }

        return $this->build($viewer, $date, $date, null, $recurringId)[0] ?? null;
    }

    /** @return array{hasRecurring: bool, paymentsCount: int, overdueCount: int} unpaid occurrences of the current month */
    public function homeCounts(Viewer $viewer): array
    {
        $has = Visibility::apply(DB::table('finance_recurring as r'), $viewer, 'r.account_id')->exists();
        if (! $has) {
            return ['hasRecurring' => false, 'paymentsCount' => 0, 'overdueCount' => 0];
        }

        [$first, $last] = $this->monthBounds(substr($this->today(), 0, 7));
        $payments = 0;
        $overdue = 0;
        foreach ($this->occurrences($viewer, $first, $last) as $occ) {
            if ($occ['status'] === BillStatus::Upcoming->value) {
                $payments++;
            } elseif ($occ['status'] === BillStatus::Overdue->value) {
                $payments++;
                $overdue++;
            }
        }

        return ['hasRecurring' => true, 'paymentsCount' => $payments, 'overdueCount' => $overdue];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVisible(Viewer $viewer, string $id, array $attributes): int
    {
        return Visibility::apply(DB::table('finance_recurring')->where('id', $id), $viewer)
            ->update($attributes + ['updated_at' => now()]);
    }

    public function deleteVisible(Viewer $viewer, string $id): int
    {
        return Visibility::apply(DB::table('finance_recurring')->where('id', $id), $viewer)->delete();
    }

    private function base(Viewer $viewer): Builder
    {
        return Visibility::apply(
            DB::table('finance_recurring as r')
                ->join('finance_accounts as a', 'a.id', '=', 'r.account_id')
                ->select('r.*', 'a.name as account_name', 'a.currency as currency'),
            $viewer,
            'r.account_id'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(Viewer $viewer, string $from, string $to, ?string $accountId, ?string $onlyId): array
    {
        $items = [];
        foreach ($this->visible($viewer, $accountId) as $item) {
            if ($onlyId === null || $item->id === $onlyId) {
                $items[(string) $item->id] = $item;
            }
        }
        if ($items === []) {
            return [];
        }

        $matches = $this->matches($viewer, array_keys($items), $from, $to);
        $today = $this->today();
        $lookbackStart = $this->shift($today, -(int) config('finance.recurring.overdue_lookback_days', 45));

        $rows = [];
        foreach ($items as $id => $item) {
            $lower = max($from, $lookbackStart, substr((string) $item->schedule_effective_from, 0, 10));
            if ($lower <= $to) {
                foreach ($this->window->dueDates($item, $lower, $to) as $date) {
                    if (! isset($matches[$id.'.'.$date])) {
                        $rows[] = $this->occurrence($item, $date, null, $today);
                    }
                }
            }
        }
        foreach ($matches as $key => $match) {
            $rows[] = $this->occurrence($items[(string) $match->recurring_id], substr((string) $match->due_date, 0, 10), $match, $today);
        }

        usort($rows, fn (array $a, array $b) => [$a['dueDate'], mb_strtolower($a['name']), $a['recurringId']] <=> [$b['dueDate'], mb_strtolower($b['name']), $b['recurringId']]);

        return $rows;
    }

    /**
     * Match rows with their transaction, read through the membership scope, keyed recurringId.dueDate.
     *
     * @param  list<string>  $recurringIds
     * @return array<string, object>
     */
    private function matches(Viewer $viewer, array $recurringIds, string $from, string $to): array
    {
        $rows = Visibility::apply(
            DB::table('finance_recurring_matches as m')
                ->join('finance_transactions as t', 't.id', '=', 'm.transaction_id')
                ->join('finance_accounts as a', 'a.id', '=', 'm.account_id'),
            $viewer,
            'm.account_id'
        )
            ->whereIn('m.recurring_id', $recurringIds)
            ->where('m.due_date', '>=', $from)
            ->where('m.due_date', '<=', $to)
            ->select('m.recurring_id', 'm.due_date', 'm.matched_by', 'm.expected_amount_minor',
                't.id as transaction_id', 't.description', 't.booked_on', 't.amount_minor', 't.kind', 'a.name as account_name')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->recurring_id.'.'.substr((string) $row->due_date, 0, 10)] = $row;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function occurrence(object $item, string $date, ?object $match, string $today): array
    {
        $expected = $match !== null ? (int) $match->expected_amount_minor : (int) $item->expected_amount_minor;
        $occurrence = new Occurrence(
            (string) $item->id,
            $date,
            $expected,
            (int) $item->tolerance_minor,
            (int) $item->window_days,
            (string) $item->account_id,
            $item->text_hint === null ? null : TextNormalizer::normalize((string) $item->text_hint),
        );

        $candidate = null;
        if ($match !== null) {
            $candidate = new MatchCandidate(
                (string) $match->transaction_id,
                (string) $item->account_id,
                substr((string) $match->booked_on, 0, 10),
                (int) $match->amount_minor,
                (string) $match->kind,
                TextNormalizer::normalize((string) ($match->description ?? '')),
            );
        }

        return [
            'key' => $occurrence->key(),
            'recurringId' => (string) $item->id,
            'name' => (string) $item->name,
            'dueDate' => $date,
            'expectedMinor' => (string) $expected,
            'currency' => (string) $item->currency,
            'accountId' => (string) $item->account_id,
            'accountName' => (string) $item->account_name,
            'categoryId' => $item->category_id,
            'status' => $this->resolver->resolve($occurrence, $candidate, $today)->value,
            'match' => $candidate === null ? null : [
                'transactionId' => $candidate->transactionId,
                'description' => $match->description,
                'bookedOn' => $candidate->bookedOn,
                'amountMinor' => (string) $candidate->amountMinor,
                'accountName' => (string) $match->account_name,
                'differenceMinor' => (string) $this->resolver->difference($occurrence, $candidate),
                'matchedBy' => (string) $match->matched_by,
            ],
        ];
    }

    /** @return array{0: string, 1: string} first and last day of a YYYY-MM month */
    private function monthBounds(string $month): array
    {
        $first = $month.'-01';

        return [$first, date('Y-m-t', (int) strtotime($first))];
    }

    private function today(): string
    {
        return now((string) config('family.timezone'))->toDateString();
    }

    private function shift(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date.' '.sprintf('%+d', $days).' days'));
    }

    private function isDate(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900 && (int) $m[1] <= 2200;
    }
}
