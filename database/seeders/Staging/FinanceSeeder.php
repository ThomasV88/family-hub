<?php

namespace Database\Seeders\Staging;

use App\Recurrence\RecurrencePattern;
use App\Recurrence\RuleBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\FinanceImportProfileFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A household in EUR with five accounts and about 3.5 years of monthly history (a few thousand rows, bulk inserted).
 *
 * Visibility is per account membership (App\Finance\Visibility): the joint account and the cash box are visible to both
 * parents and the child; the parent's private account only to parent1; savings and the credit card only to the parents.
 * So the child cannot see the private account at all, and every transfer into it (and into savings or the card) shows
 * the child only the joint leg, whose counterpart account is hidden.
 *
 * Only default categories are used (custom categories have no owner column, so a reseed could not remove them).
 * Amounts are exact integer minor units; rows follow the same check constraints as the writers (income > 0, expense < 0,
 * a transfer is a pair that shares transfer_id).
 */
final class FinanceSeeder
{
    private const MONTHS = 42;

    private StagingContext $ctx;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, string> account key => uuid */
    private array $acc = [];

    /** @var array<string, string> category default_key => uuid */
    private array $cat = [];

    /** @var list<array<string, mixed>> */
    private array $matches = [];

    private CarbonImmutable $first;

    /** @return array{accounts: int, transactions: int, recurring: int, matches: int, budgets: int, batches: int} */
    public function seed(StagingContext $ctx): array
    {
        $this->ctx = $ctx;
        $this->rows = [];
        $this->matches = [];
        mt_srand(1201);
        $this->first = $ctx->today->startOfMonth()->subMonths(self::MONTHS - 1);

        $this->categories();
        $this->accounts();
        $recurring = $this->history();
        $batches = $this->imports();

        foreach (array_chunk($this->rows, 400) as $chunk) {
            DB::table('finance_transactions')->insert($chunk);
        }
        foreach (array_chunk($this->matches, 400) as $chunk) {
            DB::table('finance_recurring_matches')->insert($chunk);
        }
        $budgets = $this->budgets();
        $this->stageDraftBatch();

        return [
            'accounts' => count($this->acc), 'transactions' => count($this->rows), 'recurring' => $recurring,
            'matches' => count($this->matches), 'budgets' => $budgets, 'batches' => $batches + 1,
        ];
    }

    private function categories(): void
    {
        $this->cat = DB::table('finance_categories')->whereNotNull('default_key')->pluck('id', 'default_key')->all();
    }

    private function accounts(): void
    {
        $p1 = $this->ctx->user('parent1')->id;
        $parents = [$p1, $this->ctx->user('parent2')->id];
        $withChild = [...$parents, $this->ctx->user('child1')->id];

        $defs = [
            'joint' => ['Spoločný účet (bežný)', 'bank', 120000, $withChild],
            'private' => ['Súkromný účet rodiča', 'bank', 50000, [$p1]],
            'savings' => ['Úspory', 'savings', 800000, $parents],
            'card' => ['Kreditná karta', 'card', 0, $parents],
            'cash' => ['Hotovosť v peňaženke', 'cash', 15000, $withChild],
        ];
        foreach ($defs as $key => [$name, $type, $opening, $members]) {
            $id = (string) Str::uuid7();
            DB::table('finance_accounts')->insert([
                'id' => $id, 'name' => $name, 'type' => $type, 'currency' => 'EUR', 'opening_balance_minor' => $opening,
                'balance_as_of' => $this->first->format('Y-m-d'), 'created_by' => $p1,
                'created_at' => $this->first, 'updated_at' => $this->first,
            ]);
            DB::table('finance_account_members')->insert(array_map(
                fn (int $user) => ['account_id' => $id, 'user_id' => $user, 'created_at' => $this->first],
                $members,
            ));
            $this->acc[$key] = $id;
        }
    }

    /** @return int recurring items created */
    private function history(): int
    {
        $p1 = $this->ctx->user('parent1')->id;
        $p2 = $this->ctx->user('parent2')->id;

        $rec = [
            'mortgage' => $this->recurring('joint', 'Splátka hypotéky', 102000, 1, 1, 0, 'housing', 'hypot'),
            'electricity' => $this->recurring('joint', 'Elektrina a plyn', 9500, 1, 15, 4500, 'housing', 'energi'),
            'internet' => $this->recurring('joint', 'Internet a televízia', 2490, 1, 8, 0, 'housing', 'internet'),
            'fitness' => $this->recurring('joint', 'Fitness permanentka', 2900, 1, 20, 0, 'leisure', 'fitness'),
            'insurance' => $this->recurring('joint', 'Poistenie auta (štvrťročne)', 24500, 3, 5, 0, 'transport', 'poisten'),
            'streaming' => $this->recurring('card', 'Streamovacia služba', 1399, 1, 18, 0, 'leisure', 'stream', $this->first->addMonths(27)->format('Y-m-d')),
        ];

        $elec = [11200, 10400, 8800, 7100, 5600, 4800, 4600, 4700, 5600, 7400, 9600, 11800];
        $cardSpend = [];

        for ($j = 0; $j < self::MONTHS; $j++) {
            $m = $this->first->addMonths($j);
            $by = $j % 2 === 0 ? $p1 : $p2;
            $raise = 6000 * intdiv($j, 12);

            // Income
            $this->txn('joint', 'income', 205000 + $raise, $this->d($m, 10), 'Výplata – zamestnávateľ A (demo)', 'salary', null, $p1);
            $this->txn('joint', 'income', 171000 + $raise, $this->d($m, 12), 'Výplata – zamestnávateľ B (demo)', 'salary', null, $p2);
            $this->txn('joint', 'income', 5500, $this->d($m, 5), 'Prídavok na deti (demo)', 'benefits', null, $p1);

            // Fixed bills, matched to their recurring item
            $this->bill($rec['mortgage'], $m, 'Splátka hypotéky (demo)', 102000, 0, $p1);
            $this->bill($rec['electricity'], $m, 'Elektrina a plyn – záloha (demo)', $elec[(int) $m->format('n') - 1] - 1000 + ($j % 3) * 400, 1, $p1);
            $this->bill($rec['internet'], $m, 'Internet a TV (demo)', 2490, 0, $p2);
            $this->txn('joint', 'expense', 3450, $this->d($m, 20), 'Voda a odpad (demo)', 'housing', null, $p1);
            $this->txn('joint', 'expense', 1990, $this->d($m, 22), 'Mobilný paušál (demo)', 'other', null, $p2);
            // The latest fitness fee that is already past due stays unpaid on purpose (an overdue bill).
            $due = $this->d($m, 20);
            $latestPastDue = $this->latestPastDue(20);
            if ($due !== $latestPastDue) {
                $this->bill($rec['fitness'], $m, 'Fitness permanentka (demo)', 2900, 1, $p2);
            }
            if ($j % 3 === 0) {
                $this->bill($rec['insurance'], $m, 'Poistenie auta (demo)', $j === 27 ? 25900 : 24500, 0, $p1);
            }

            // Groceries, transport, kids, health, leisure
            foreach ([2, 5, 8, 11, 15, 19, 23, 27] as $k => $day) {
                $drug = $k === 6;
                $this->txn('joint', 'expense', mt_rand(1500, $k % 3 === 0 ? 11800 : 6400), $this->d($m, $day + mt_rand(0, 1)),
                    $drug ? 'Drogéria (demo)' : ['Supermarket (demo)', 'Pekáreň (demo)', 'Trh – zelenina a ovocie (demo)', 'Supermarket (demo)'][$k % 4],
                    $drug ? 'other' : 'groceries', null, $k % 2 === 0 ? $p1 : $p2);
            }
            $this->txn('joint', 'expense', mt_rand(4600, 7400), $this->d($m, 9), 'Palivo – čerpacia stanica (demo)', 'transport', null, $p1);
            $this->txn('joint', 'expense', mt_rand(4200, 6900), $this->d($m, 24), 'Palivo – čerpacia stanica (demo)', 'transport', null, $p2);
            $this->txn('joint', 'expense', 4800, $this->d($m, 3), 'Stravné v škole (demo)', 'kids', null, $p2);
            $this->txn('joint', 'expense', mt_rand(2500, 4500), $this->d($m, 16), 'Krúžky a športový klub (demo)', 'kids', null, $p2);
            $this->txn('joint', 'expense', mt_rand(600, 3400), $this->d($m, 13), 'Lekáreň (demo)', 'health', null, $p1);
            $this->txn('joint', 'expense', mt_rand(3200, 7800), $this->d($m, 14), 'Reštaurácia (demo)', 'leisure', null, $p1);
            $this->txn('joint', 'expense', mt_rand(1800, 4200), $this->d($m, 26), 'Kino a zmrzlina (demo)', 'leisure', null, $p2);

            // Transfers out of the joint account; the counterpart accounts are hidden from the child
            $this->transfer('joint', 'savings', 40000, $this->d($m, 11), 'Mesačné sporenie', null, $p1);
            $this->transfer('joint', 'private', 30000, $this->d($m, 11), 'Vreckové', null, $p1);
            if ($j % 2 === 1) {
                $this->transfer('joint', 'cash', 10000, $this->d($m, 6), 'Výber z bankomatu', null, $p2);
            }
            if ($j > 0) {
                $prev = $cardSpend[$j - 1] ?? 0;
                if ($prev > 0) {
                    $this->transfer('joint', 'card', $prev, $this->d($m, 3), 'Splatenie kreditnej karty', 'Za minulý mesiac', $p1);
                }
            }

            // Yearly and one-off items
            $month = (int) $m->format('n');
            if ($month === 7) {
                $this->transfer('savings', 'joint', 150000, $this->d($m, 4), 'Z úspor na dovolenku', null, $p1);
                $this->txn('joint', 'expense', mt_rand(185000, 232000), $this->d($m, 6), 'Letná dovolenka pri mori (demo)', 'leisure', 'Ubytovanie a doprava pre celú rodinu.', $p1);
            }
            if ($month === 9) {
                $this->txn('joint', 'expense', mt_rand(18000, 25000), $this->d($m, 3), 'Školské potreby a tašky (demo)', 'kids', null, $p2);
            }
            if ($month === 12) {
                $this->txn('joint', 'expense', mt_rand(38000, 45000), $this->d($m, 12), 'Vianočné darčeky (demo)', 'leisure', null, $p1);
            }
            foreach ([7 => ['Práčka (demo)', 68900, 'housing'], 19 => ['Notebook pre rodinu (demo)', 124900, 'other'], 30 => ['Oprava auta po kolízii (demo)', 64000, 'transport'],
                36 => ['Nová pohovka (demo)', 89900, 'housing'], 40 => ['Zubár – väčšie ošetrenie (demo)', 34000, 'health']] as $at => [$desc, $amt, $cat]) {
                if ($at === $j) {
                    $this->txn('joint', 'expense', $amt, $this->d($m, 17), $desc, $cat, 'Jednorazový väčší nákup.', $p1);
                }
            }

            // Private account of parent1
            $this->txn('private', 'expense', mt_rand(1500, 6500), $this->d($m, 14), 'Koníček (demo)', 'leisure', null, $p1);
            $this->txn('private', 'expense', mt_rand(700, 1500), $this->d($m, 18), 'Obed v práci (demo)', 'other', null, $p1);
            $this->txn('private', 'expense', mt_rand(900, 4000), $this->d($m, 25), 'Knihy a hudba (demo)', 'leisure', null, $p1);
            if ($j % 3 === 0) {
                $this->txn('private', 'income', 25000, $this->d($m, 21), 'Vedľajší príjem – drobná zákazka (demo)', 'other_income', null, $p1);
            }

            // Savings interest, cash box
            $this->txn('savings', 'income', mt_rand(90, 140), $this->d($m, 28), 'Úrok z úspor (demo)', 'other_income', null, $p1);
            foreach ([8, 19] as $day) {
                $this->txn('cash', 'expense', mt_rand(500, 3200), $this->d($m, $day), 'Drobné nákupy v hotovosti (demo)', 'other', null, $by);
            }

            // Credit card purchases (their total is repaid next month)
            $spent = 0;
            foreach ([[7, 'Online obchod (demo)', 'other', 1500, 9900], [12, 'Reštaurácia (demo)', 'leisure', 2400, 6800],
                [21, 'Čerpacia stanica (demo)', 'transport', 4800, 7200], [27, 'Domáce potreby (demo)', 'housing', 1900, 6200]] as [$day, $desc, $cat, $lo, $hi]) {
                $amount = mt_rand($lo, $hi);
                if ($this->txn('card', 'expense', $amount, $this->d($m, $day), $desc, $cat, null, $p1) !== null) {
                    $spent += $amount;
                }
            }
            $price = $j >= 27 ? 1399 : 1199;
            $streamingId = $this->txn('card', 'expense', $price, $this->d($m, 18), 'Streamovacia služba (demo)', 'leisure', null, $p1);
            if ($streamingId !== null) {
                $spent += $price;
                if ($j >= 27) {
                    $this->match($rec['streaming'], $this->d($m, 18), $streamingId, 1399, $p1);
                }
            }
            if ($j === 25) {
                $this->txn('card', 'expense', 45900, $this->d($m, 10), 'Tablet pre dieťa (demo)', 'other', 'Veľký nákup na kartu.', $p1);
                $spent += 45900;
            }
            $cardSpend[$j] = $spent;
        }

        return count($rec);
    }

    /** First day of the latest due date (of the given day of month) that is not in the future. */
    private function latestPastDue(int $day): string
    {
        $t = $this->ctx->today;
        $m = $t->startOfMonth();
        $candidate = $this->d($m, $day);
        if ($candidate >= $t->format('Y-m-d')) {
            $candidate = $this->d($m->subMonthNoOverflow(), $day);
        }

        return $candidate;
    }

    private function d(CarbonImmutable $month, int $day): string
    {
        return $month->addDays(min($day, $month->daysInMonth) - 1)->format('Y-m-d');
    }

    /**
     * Adds one transaction row; a date after today is skipped (null returned).
     */
    private function txn(string $account, string $kind, int $magnitude, string $date, string $description, ?string $category, ?string $note, int $by, ?string $transferId = null, ?int $signed = null): ?string
    {
        if ($date > $this->ctx->today->format('Y-m-d')) {
            return null;
        }
        $id = (string) Str::uuid7();
        $stamp = CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('family.timezone'))->setTime(12, 0);
        $this->rows[] = [
            'id' => $id, 'account_id' => $this->acc[$account], 'currency' => 'EUR', 'kind' => $kind,
            'amount_minor' => $signed ?? ($kind === 'income' ? $magnitude : -$magnitude), 'booked_on' => $date,
            'description' => $description, 'note' => $note, 'category_id' => $category === null ? null : $this->cat[$category],
            'transfer_id' => $transferId, 'source' => 'manual', 'import_batch_id' => null, 'import_hash' => null,
            'reconciled_batch_id' => null, 'edited_at' => null, 'created_by' => $by,
            'created_at' => $stamp, 'updated_at' => $stamp,
        ];

        return $id;
    }

    private function transfer(string $from, string $to, int $amount, string $date, string $description, ?string $note, int $by): void
    {
        $transferId = (string) Str::uuid7();
        $out = $this->txn($from, 'transfer', $amount, $date, $description, null, $note, $by, $transferId, -$amount);
        if ($out === null) {
            return;
        }
        $this->txn($to, 'transfer', $amount, $date, $description, null, null, $by, $transferId, $amount);
    }

    /**
     * Writes a recurring item and returns the handle used by bill()/match().
     *
     * @return array{id: string, account: string, rule: string, day: int, expected: int, category: string}
     */
    private function recurring(string $account, string $name, int $expected, int $everyMonths, int $day, int $tolerance, string $category, string $hint, ?string $effectiveFrom = null): array
    {
        $startsOn = $this->d($this->first, $day);
        $rule = RuleBuilder::toRrule(RecurrencePattern::fromArray($everyMonths === 1
            ? ['kind' => 'monthly_day', 'monthDay' => $day]
            : ['kind' => 'custom', 'interval' => $everyMonths, 'unit' => 'month', 'monthDay' => $day]));
        $id = (string) Str::uuid7();
        DB::table('finance_recurring')->insert([
            'id' => $id, 'account_id' => $this->acc[$account], 'category_id' => $this->cat[$category], 'name' => $name,
            'expected_amount_minor' => $expected, 'recurrence_rule' => $rule, 'starts_on' => $startsOn, 'ends_on' => null,
            'schedule_effective_from' => $effectiveFrom ?? $startsOn, 'tolerance_minor' => $tolerance, 'window_days' => 5,
            'text_hint' => $hint, 'created_by' => $this->ctx->user('parent1')->id,
            'created_at' => $this->first, 'updated_at' => $this->first,
        ]);

        return ['id' => $id, 'account' => $account, 'rule' => $rule, 'day' => $day, 'expected' => $expected, 'category' => $category];
    }

    /** One bill payment: a joint-account expense a few days after the due date, auto-matched to the recurring item. */
    /** @param  array{id: string, account: string, day: int, expected: int, category: string}  $rec */
    private function bill(array $rec, CarbonImmutable $month, string $description, int $amount, int $lateDays, int $by): void
    {
        $due = $this->d($month, $rec['day']);
        $booked = CarbonImmutable::createFromFormat('Y-m-d', $due)->addDays($lateDays)->format('Y-m-d');
        $id = $this->txn($rec['account'], 'expense', $amount, $booked, $description, $rec['category'], null, $by);
        if ($id !== null) {
            $this->match($rec, $due, $id, $rec['expected'], $by);
        }
    }

    /** @param  array{id: string, account: string}  $rec */
    private function match(array $rec, string $due, string $transactionId, int $expected, int $by): void
    {
        $this->matches[] = [
            'id' => (string) Str::uuid7(), 'recurring_id' => $rec['id'], 'account_id' => $this->acc[$rec['account']], 'due_date' => $due,
            'transaction_id' => $transactionId, 'matched_by' => 'auto', 'expected_amount_minor' => $expected, 'created_by' => $by,
            'created_at' => CarbonImmutable::createFromFormat('Y-m-d', $due)->setTime(12, 5),
        ];
    }

    /** @return int number of budget rows */
    private function budgets(): int
    {
        $p1 = $this->ctx->user('parent1')->id;
        $year = $this->ctx->today->startOfMonth()->subMonths(12)->format('Y-m-d');
        $prev = $this->ctx->today->startOfMonth()->subMonthNoOverflow()->format('Y-m-d');

        $rows = [
            ['groceries', 65000, $year], ['groceries', 70000, $prev],
            ['transport', 18000, $year], ['kids', 12000, $year], ['kids', 15000, $prev],
            ['leisure', 9000, $prev], ['health', 4000, $prev], ['other', 8000, $prev],
        ];
        foreach ($rows as [$category, $amount, $from]) {
            DB::table('finance_budgets')->insert([
                'id' => (string) Str::uuid7(), 'category_id' => $this->cat[$category], 'currency' => 'EUR', 'amount_minor' => $amount,
                'effective_from' => $from, 'created_by' => $p1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // An overspent leisure budget this month: one more outing right before today.
        $day = max(1, (int) $this->ctx->today->format('j') - 1);
        $overspend = (string) Str::uuid7();
        DB::table('finance_transactions')->insert([
            'id' => $overspend, 'account_id' => $this->acc['joint'], 'currency' => 'EUR', 'kind' => 'expense', 'amount_minor' => -14500,
            'booked_on' => $this->d($this->ctx->today->startOfMonth(), $day), 'description' => 'Oslava narodenín – reštaurácia (demo)',
            'note' => 'Prekročený rozpočet na voľný čas.', 'category_id' => $this->cat['leisure'], 'transfer_id' => null, 'source' => 'manual',
            'created_by' => $p1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return count($rows);
    }

    /** @return int committed batches: one batch whose rows became import transactions on the joint account */
    private function imports(): int
    {
        $p1 = $this->ctx->user('parent1')->id;
        $profile = FinanceImportProfileFactory::skSettings();
        DB::table('finance_import_profiles')->insert([
            'id' => (string) Str::uuid7(), 'name' => 'Banka SK – výpis CSV (demo)', 'settings' => json_encode($profile),
            'created_by' => $p1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The groceries/transport/leisure rows of one old month on the joint account count as imported from a bank file.
        $month = $this->first->addMonths(self::MONTHS - 6);
        $from = $month->format('Y-m-d');
        $to = $month->endOfMonth()->format('Y-m-d');
        $batchId = (string) Str::uuid7();
        $imported = [];
        foreach ($this->rows as $i => $row) {
            if ($row['account_id'] === $this->acc['joint'] && $row['kind'] === 'expense' && $row['booked_on'] >= $from && $row['booked_on'] <= $to
                && in_array($row['category_id'], [$this->cat['groceries'], $this->cat['transport'], $this->cat['leisure']], true)) {
                $imported[] = $i;
            }
        }
        DB::table('finance_import_batches')->insert([
            'id' => $batchId, 'account_id' => $this->acc['joint'], 'user_id' => $p1, 'status' => 'committed', 'profile_snapshot' => json_encode($profile),
            'row_count' => count($imported), 'added_count' => count($imported), 'matched_count' => 0, 'kept_count' => 0,
            'committed_at' => $month->endOfMonth()->setTime(20, 0), 'created_at' => $month->endOfMonth()->setTime(19, 55), 'updated_at' => $month->endOfMonth()->setTime(20, 0),
        ]);
        foreach ($imported as $n => $i) {
            $row = &$this->rows[$i];
            $hash = hash('sha256', $row['booked_on'].'|'.$row['amount_minor'].'|'.$row['description'].'|'.$n);
            $row['source'] = 'import';
            $row['import_batch_id'] = $batchId;
            $row['import_hash'] = $hash;
            DB::table('finance_import_rows')->insert([
                'id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'row_no' => $n + 5, 'booked_on' => $row['booked_on'],
                'amount_minor' => $row['amount_minor'], 'kind' => 'expense', 'description' => $row['description'],
                'counterparty' => null, 'reference' => null, 'import_hash' => $hash, 'state' => 'new', 'error_code' => null, 'include' => true,
                'category_id' => $row['category_id'], 'created_at' => $month->endOfMonth()->setTime(19, 55),
            ]);
            unset($row);
        }

        return 1;
    }

    /** A draft import on the credit card (the 24 h draft is pruned by the scheduler, so it exists right after a deploy). */
    private function stageDraftBatch(): void
    {
        $batchId = (string) Str::uuid7();
        $p1 = $this->ctx->user('parent1')->id;
        $profile = FinanceImportProfileFactory::skSettings();
        DB::table('finance_import_batches')->insert([
            'id' => $batchId, 'account_id' => $this->acc['card'], 'user_id' => $p1, 'status' => 'draft', 'profile_snapshot' => json_encode($profile),
            'row_count' => 4, 'added_count' => 0, 'matched_count' => 0, 'kept_count' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $today = $this->ctx->today;
        $rows = [
            [1, 'Obchod s elektronikou (demo)', -8990, 'new', true, null],
            [2, 'Kaviareň (demo)', -430, 'new', true, null],
            [3, 'Čerpacia stanica (demo)', -5200, 'duplicate', false, null],
            [4, 'Neplatný riadok', null, 'invalid', false, 'amount'],
        ];
        foreach ($rows as [$no, $description, $amount, $state, $include, $error]) {
            DB::table('finance_import_rows')->insert([
                'id' => (string) Str::uuid7(), 'batch_id' => $batchId, 'row_no' => $no + 4,
                'booked_on' => $state === 'invalid' ? null : $today->subDays($no)->format('Y-m-d'), 'amount_minor' => $amount,
                'kind' => $amount === null ? null : 'expense', 'description' => $description, 'counterparty' => null, 'reference' => null,
                'import_hash' => $state === 'invalid' ? null : hash('sha256', "draft|{$no}|{$description}"), 'state' => $state,
                'error_code' => $error, 'include' => $include, 'created_at' => now(),
            ]);
        }
    }
}
