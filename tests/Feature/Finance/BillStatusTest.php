<?php

use App\Access\Preset;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinancePrivacy;

function fhRecState(callable $fn): ?string
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        return (string) $e->getPrevious()?->getCode();
    }

    return null;
}

function fhRecSetup(string $today = '2027-03-10'): array
{
    test()->travelTo(CarbonImmutable::parse($today.' 09:00', config('family.timezone')));
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);

    return [$user, Viewer::for($user), $account, app(RecurringQuery::class)];
}

function fhRecItem(FinanceAccount $account, string $startsOn, array $attrs = []): FinanceRecurring
{
    return FinanceRecurring::factory()->onAccount($account)->startingOn($startsOn)->create($attrs + ['name' => 'Rent']);
}

function fhRecPay(FinanceRecurring $item, string $due, string $bookedOn, int $amount, string $matchedBy = 'auto'): FinanceTransaction
{
    $tx = FinanceTransaction::factory()->onAccount(FinanceAccount::query()->findOrFail($item->account_id))->expense($amount)
        ->create(['booked_on' => $bookedOn, 'description' => 'Landlord']);
    FinanceRecurringMatch::factory()->between($item, $due, $tx)->create(['matched_by' => $matchedBy]);

    return $tx;
}

function fhRecDates(array $occurrences): array
{
    return array_map(fn ($o) => $o['dueDate'], $occurrences);
}

it('refuses a match or rejection whose transaction is on another account, whatever account_id says', function () {
    $a = FinanceAccount::factory()->create();
    $b = FinanceAccount::factory()->create();
    $item = fhRecItem($a, '2027-03-01');
    $foreign = FinanceTransaction::factory()->onAccount($b)->expense(500)->create(['booked_on' => '2027-03-01', 'description' => 'x']);

    foreach (['finance_recurring_matches', 'finance_match_rejections'] as $table) {
        foreach ([$a->id, $b->id] as $accountId) {
            $row = ['id' => (string) Str::uuid7(), 'recurring_id' => $item->id, 'account_id' => $accountId,
                'due_date' => '2027-03-01', 'transaction_id' => $foreign->id, 'created_at' => now()];
            if ($table === 'finance_recurring_matches') {
                $row += ['matched_by' => 'auto', 'expected_amount_minor' => 50000];
            }
            expect(fhRecState(fn () => DB::table($table)->insert($row)))->toBe('23503');
        }
    }
});

it('refuses to move the account of a matched transaction or a matched recurring item', function () {
    $a = FinanceAccount::factory()->create();
    $b = FinanceAccount::factory()->create();
    $item = fhRecItem($a, '2027-03-01');
    $tx = fhRecPay($item, '2027-03-01', '2027-03-02', 50000);

    expect(fhRecState(fn () => DB::table('finance_transactions')->where('id', $tx->id)->update(['account_id' => $b->id, 'currency' => $b->currency])))->toBe('23503')
        ->and(fhRecState(fn () => DB::table('finance_recurring')->where('id', $item->id)->update(['account_id' => $b->id])))->toBe('23503');
});

it('enforces one match per occurrence and per transaction, and the column checks', function () {
    $a = FinanceAccount::factory()->create();
    $item = fhRecItem($a, '2027-03-01');
    $tx = fhRecPay($item, '2027-03-01', '2027-03-02', 50000);
    $other = FinanceTransaction::factory()->onAccount($a)->expense(500)->create(['booked_on' => '2027-03-02', 'description' => 'y']);
    $item2 = fhRecItem($a, '2027-03-05', ['name' => 'Other']);

    $match = fn (FinanceRecurring $r, string $due, FinanceTransaction $t) => fn () => DB::table('finance_recurring_matches')->insert([
        'id' => (string) Str::uuid7(), 'recurring_id' => $r->id, 'account_id' => $a->id, 'due_date' => $due,
        'transaction_id' => $t->id, 'matched_by' => 'auto', 'expected_amount_minor' => 1, 'created_at' => now(),
    ]);

    expect(fhRecState($match($item, '2027-03-01', $other)))->toBe('23505')
        ->and(fhRecState($match($item2, '2027-03-05', $tx)))->toBe('23505')
        ->and(fhRecState(fn () => DB::table('finance_recurring')->where('id', $item->id)->update(['window_days' => 4])))->toBe('23514')
        ->and(fhRecState(fn () => DB::table('finance_recurring')->where('id', $item->id)->update(['expected_amount_minor' => 0])))->toBe('23514')
        ->and(fhRecState(fn () => DB::table('finance_recurring')->where('id', $item->id)->update(['ends_on' => '2026-01-01'])))->toBe('23514');
});

it('shows a day-31 monthly item on the last day of short months, without gaps or duplicates', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-01-05');
    fhRecItem($account, '2027-01-31');

    $dates = [];
    foreach (['2027-01', '2027-02', '2027-03', '2027-04'] as $month) {
        $calendar = $query->calendar($viewer, $month);
        expect($calendar)->toHaveCount((int) date('t', strtotime($month.'-01')));
        $dates[] = array_values(array_filter(array_map(fn ($d) => $d['occurrences'] === [] ? null : $d['date'], $calendar)));
    }

    expect($dates)->toBe([['2027-01-31'], ['2027-02-28'], ['2027-03-31'], ['2027-04-30']]);
});

it('derives upcoming, overdue, paid and paid differently, with integer differences', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $item = fhRecItem($account, '2027-01-01', ['expected_amount_minor' => 50000, 'tolerance_minor' => 0]);
    fhRecPay($item, '2027-02-01', '2027-02-02', 50000);
    fhRecPay($item, '2027-03-01', '2027-03-02', 51000);

    $feb = collect($query->occurrences($viewer, '2027-02-01', '2027-02-28'))->keyBy('dueDate');
    $mar = $query->occurrences($viewer, '2027-03-01', '2027-03-31');

    expect($feb['2027-02-01']['status'])->toBe('paid')
        ->and($feb['2027-02-01']['match']['matchedBy'])->toBe('auto')
        ->and($mar)->toHaveCount(1)
        ->and($mar[0]['status'])->toBe('paid_differently')
        ->and($mar[0]['match']['differenceMinor'])->toBe('1000')
        ->and($mar[0]['match']['amountMinor'])->toBe('-51000')
        ->and($mar[0]['expectedMinor'])->toBe('50000')
        ->and($mar[0]['accountName'])->toBe('Main');
});

it('marks unmatched past occurrences overdue and future ones upcoming, and hides ones beyond the lookback', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    fhRecItem($account, '2027-03-01');
    fhRecItem($account, '2027-03-15', ['name' => 'Energy']);
    fhRecItem($account, '2026-12-01', ['name' => 'Old']);

    $occ = collect($query->occurrences($viewer, '2026-12-01', '2027-03-31'))->map(fn ($o) => $o['name'].' '.$o['dueDate'].' '.$o['status'])->all();

    expect($occ)->toContain('Rent 2027-03-01 overdue', 'Energy 2027-03-15 upcoming', 'Old 2027-03-01 overdue')
        ->and($occ)->not->toContain('Old 2026-12-01 overdue', 'Old 2027-01-01 overdue')
        ->and(collect($occ)->contains(fn ($s) => str_starts_with($s, 'Old 2027-01-01')))->toBeFalse();
});

it('keeps matched occurrences and hides unmatched earlier ones after the schedule changes', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $item = fhRecItem($account, '2027-01-01');
    fhRecPay($item, '2027-03-01', '2027-03-01', 50000);
    $query->updateVisible($viewer, $item->id, ['expected_amount_minor' => 60000, 'schedule_effective_from' => '2027-03-10']);

    $mar = $query->occurrences($viewer, '2027-03-01', '2027-03-31');
    $feb = $query->occurrences($viewer, '2027-02-01', '2027-02-28');

    expect($mar)->toHaveCount(1)
        ->and($mar[0]['status'])->toBe('paid')
        ->and($mar[0]['expectedMinor'])->toBe('50000')
        ->and($feb)->toBe([]);
});

it('returns an occurrence to overdue when its matched transaction is deleted', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $item = fhRecItem($account, '2027-03-01');
    $tx = fhRecPay($item, '2027-03-01', '2027-03-02', 50000);

    expect($query->occurrences($viewer, '2027-03-01', '2027-03-31')[0]['status'])->toBe('paid');
    DB::table('finance_transactions')->where('id', $tx->id)->delete();
    expect($query->occurrences($viewer, '2027-03-01', '2027-03-31')[0]['status'])->toBe('overdue')
        ->and(DB::table('finance_recurring_matches')->count())->toBe(0);
});

it('lists overdue first, then the next 30 days across a month boundary', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-20');
    fhRecItem($account, '2027-03-05', ['name' => 'A']);
    fhRecItem($account, '2027-03-12', ['name' => 'B']);
    fhRecItem($account, '2027-03-25', ['name' => 'C']);
    fhRecItem($account, '2027-03-02', ['name' => 'D']);
    $list = $query->upcoming($viewer);

    expect(fhRecDates($list['overdue']))->toBe(['2027-03-02', '2027-03-05', '2027-03-12'])
        ->and(fhRecDates($list['next30']))->toBe(['2027-03-25', '2027-04-02', '2027-04-05', '2027-04-12'])
        ->and(array_column($list['next30'], 'name'))->toBe(['C', 'D', 'A', 'B']);
});

it('sums still to pay per currency over visible accounts only, in the currency order of the visible accounts', function () {
    [$user, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $czk = FinanceAccount::factory()->withMembers($user)->create(['currency' => 'CZK', 'name' => 'Koruna']);
    $czk2 = FinanceAccount::factory()->withMembers($user)->create(['currency' => 'CZK', 'name' => 'Koruna 2']);
    $hidden = FinanceAccount::factory()->create(['name' => 'Hidden']);
    fhRecItem($account, '2027-03-01', ['expected_amount_minor' => 45000]);
    fhRecItem($account, '2027-03-20', ['expected_amount_minor' => 1005, 'name' => 'Eur 2']);
    fhRecItem($czk, '2027-03-25', ['expected_amount_minor' => 99900, 'name' => 'Czk']);
    $paid = fhRecItem($czk, '2027-03-02', ['expected_amount_minor' => 7000, 'name' => 'Paid czk']);
    fhRecPay($paid, '2027-03-02', '2027-03-02', 7000);
    fhRecItem($hidden, '2027-03-15', ['expected_amount_minor' => 777777, 'name' => 'ZxCanaryFin rent']);

    $blocks = $query->stillToPay($viewer, '2027-03');
    $only = $query->stillToPay($viewer, '2027-03', $account->id);

    expect(array_column($blocks, 'currency'))->toBe(['CZK', 'EUR'])
        ->and($blocks[0])->toMatchArray(['sumMinor' => '99900', 'count' => 1, 'overdueCount' => 0])
        ->and($blocks[1])->toMatchArray(['sumMinor' => '46005', 'count' => 2, 'overdueCount' => 1])
        ->and(array_column($blocks[1]['next'], 'name'))->toBe(['Rent', 'Eur 2'])
        ->and(json_encode($blocks))->not->toContain('ZxCanaryFin')->not->toContain('777777')
        ->and($czk2->id)->not->toBe($czk->id)
        ->and(array_column($only, 'currency'))->toBe(['EUR']);
});

it('counts the unpaid items of the current month for Home, from visible accounts only', function () {
    [$user, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $hidden = FinanceAccount::factory()->create();
    $none = Viewer::for(User::factory()->withPreset(Preset::Parent)->create());

    expect($query->homeCounts($viewer))->toBe(['hasRecurring' => false, 'paymentsCount' => 0, 'overdueCount' => 0]);

    fhRecItem($account, '2027-03-01');
    fhRecItem($account, '2027-03-20', ['name' => 'Energy']);
    $paid = fhRecItem($account, '2027-03-05', ['name' => 'Paid']);
    fhRecPay($paid, '2027-03-05', '2027-03-05', 50000);
    fhRecItem($hidden, '2027-03-02');

    expect($query->homeCounts($viewer))->toBe(['hasRecurring' => true, 'paymentsCount' => 2, 'overdueCount' => 1])
        ->and($query->homeCounts($none))->toBe(['hasRecurring' => false, 'paymentsCount' => 0, 'overdueCount' => 0]);
});

it('resolves a bill only for a visible item and a real due date', function () {
    [, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $hidden = fhRecItem(FinanceAccount::factory()->create(), '2027-03-01');
    $item = fhRecItem($account, '2027-03-01');

    expect($query->bill($viewer, $item->id, '2027-03-01')['key'])->toBe($item->id.'.2027-03-01')
        ->and($query->bill($viewer, $item->id, '2027-03-02'))->toBeNull()
        ->and($query->bill($viewer, $item->id, '2027-02-30'))->toBeNull()
        ->and($query->bill($viewer, $hidden->id, '2027-03-01'))->toBeNull()
        ->and($query->bill($viewer, 'nope', '2027-03-01'))->toBeNull();
});

it('keeps every recurring statement behind the membership scope', function () {
    [$user, $viewer, $account, $query] = fhRecSetup('2027-03-10');
    $item = fhRecItem($account, '2027-03-01');
    fhRecPay($item, '2027-03-01', '2027-03-02', 50000);

    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });
    $query->visible($viewer);
    $query->findVisible($viewer, $item->id);
    $query->occurrences($viewer, '2027-03-01', '2027-03-31');
    $query->calendar($viewer, '2027-03');
    $query->upcoming($viewer);
    $query->stillToPay($viewer, '2027-03');
    $query->bill($viewer, $item->id, '2027-03-01');
    $query->homeCounts($viewer);

    $own = array_values(array_filter($statements, fn ($s) => preg_match('/finance_recurring|finance_transactions/', $s) === 1));
    expect($own)->not->toBe([])
        ->and(FinancePrivacy::unscopedReads($statements))->toBe([]);
});
