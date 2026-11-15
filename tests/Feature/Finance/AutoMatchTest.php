<?php

use App\Access\Preset;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Recurring\RecurringMatchService;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00', config('family.timezone')));
});

function fhAmSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);

    return [$user, $account];
}

function fhAmRent(FinanceAccount $account, array $attrs = [], string $startsOn = '2027-03-05'): FinanceRecurring
{
    return FinanceRecurring::factory()->onAccount($account)->startingOn($startsOn)->create($attrs + ['name' => 'Rent', 'expected_amount_minor' => 45000]);
}

function fhAmPay($test, User $user, FinanceAccount $account, string $amount, string $date, string $description = 'Payment')
{
    return $test->actingAs($user)->post('/finance/transactions', [
        'kind' => 'expense', 'amount' => $amount, 'booked_on' => $date, 'description' => $description, 'account_id' => $account->id,
    ])->assertStatus(303);
}

function fhAmStatus(User $user, FinanceRecurring $item, string $date): ?string
{
    return app(RecurringQuery::class)->bill(Viewer::for($user), (string) $item->id, $date)['status'] ?? null;
}

it('auto matches an exact payment within the window and reads Paid', function () {
    [$user, $account] = fhAmSetup();
    $item = fhAmRent($account);
    expect(fhAmStatus($user, $item, '2027-03-05'))->toBe('overdue');

    fhAmPay($this, $user, $account, '450,00', '2027-03-06');

    $match = FinanceRecurringMatch::query()->firstOrFail();
    expect($match->matched_by)->toBe('auto')
        ->and($match->account_id)->toBe($account->id)
        ->and($match->recurring_id)->toBe($item->id)
        ->and($match->expected_amount_minor)->toBe(45000)
        ->and(fhAmStatus($user, $item, '2027-03-05'))->toBe('paid');
});

it('reads Paid within tolerance and Paid differently when only the text hint fits', function () {
    [$user, $account] = fhAmSetup();
    $tolerant = fhAmRent($account, ['tolerance_minor' => 150, 'name' => 'Tolerant']);
    fhAmPay($this, $user, $account, '450,50', '2027-03-06');
    expect(fhAmStatus($user, $tolerant, '2027-03-05'))->toBe('paid');

    $hinted = fhAmRent($account, ['text_hint' => 'landlord', 'name' => 'Hinted'], '2027-03-07');
    fhAmPay($this, $user, $account, '480,00', '2027-03-08', 'Landlord March');
    expect(fhAmStatus($user, $hinted, '2027-03-07'))->toBe('paid_differently');

    // Neither tolerance nor hint: no match at all.
    $plain = fhAmRent($account, ['name' => 'Plain'], '2027-03-09');
    fhAmPay($this, $user, $account, '999,00', '2027-03-09', 'Something');
    expect(fhAmStatus($user, $plain, '2027-03-09'))->toBe('overdue');
});

it('lets one payment claim only one of two overdue months and is idempotent', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-20 09:00', config('family.timezone')));
    [$user, $account] = fhAmSetup();
    $item = fhAmRent($account, [], '2027-01-15');

    $tx = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-02-14', 'description' => 'Rent']);
    $service = app(RecurringMatchService::class);
    $first = $service->matchTransactions(Viewer::for($user), [(string) $tx->id]);
    $again = $service->matchTransactions(Viewer::for($user), [(string) $tx->id]);

    expect($first)->toBe(1)
        ->and($again)->toBe(0)
        ->and(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->due_date->format('Y-m-d'))->toBe('2027-02-15')
        ->and(fhAmStatus($user, $item, '2027-02-15'))->toBe('paid')
        ->and(fhAmStatus($user, $item, '2027-01-15'))->toBe('overdue');
});

it('lets a negative transfer leg pay a savings item but never the incoming leg', function () {
    [$user, $account] = fhAmSetup();
    $second = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Savings']);
    $item = fhAmRent($account, ['name' => 'Savings plan']);

    $this->actingAs($user)->post('/finance/transactions', [
        'kind' => 'transfer', 'amount' => '450,00', 'booked_on' => '2027-03-05', 'description' => 'Save',
        'from_account_id' => $account->id, 'to_account_id' => $second->id,
    ])->assertStatus(303);

    $out = FinanceTransaction::query()->where('account_id', $account->id)->firstOrFail();
    expect(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->transaction_id)->toBe($out->id)
        ->and(fhAmStatus($user, $item, '2027-03-05'))->toBe('paid');
});

it('never matches a transaction of another account, and never reads one the viewer cannot see', function () {
    [$user, $account] = fhAmSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $visibleOther = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Other visible']);
    $hidden = FinanceAccount::factory()->withMembers($other)->create(['name' => 'ZxCanaryFin hidden']);
    $item = fhAmRent($account);

    $a = FinanceTransaction::factory()->onAccount($visibleOther)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Rent']);
    $b = FinanceTransaction::factory()->onAccount($hidden)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Rent']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $count = app(RecurringMatchService::class)->matchTransactions(Viewer::for($user), [(string) $a->id, (string) $b->id]);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    expect($count)->toBe(0)
        ->and(FinanceRecurringMatch::query()->count())->toBe(0)
        ->and(fhAmStatus($user, $item, '2027-03-05'))->toBe('overdue')
        ->and(FinancePrivacy::unscopedReads($statements))->toBe([]);
});

it('never re-matches an unmatched pair and never replaces a manual match', function () {
    [$user, $account] = fhAmSetup();
    $item = fhAmRent($account);
    $viewer = Viewer::for($user);
    $service = app(RecurringMatchService::class);
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-06', 'description' => 'Rent']);

    expect($service->matchTransactions($viewer, [(string) $tx->id]))->toBe(1);
    $service->unmatch($viewer, $item, '2027-03-05');
    expect(FinanceRecurringMatch::query()->count())->toBe(0)
        ->and(DB::table('finance_match_rejections')->where('account_id', $account->id)->count())->toBe(1)
        ->and($service->matchTransactions($viewer, [(string) $tx->id]))->toBe(0);

    $manual = FinanceTransaction::factory()->onAccount($account)->expense(48000)->create(['booked_on' => '2027-03-07', 'description' => 'Other']);
    $service->matchManually($viewer, $item, '2027-03-05', (string) $manual->id);
    $exact = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Rent again']);

    expect($service->matchTransactions($viewer, [(string) $exact->id]))->toBe(0)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->transaction_id)->toBe($manual->id)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->matched_by)->toBe('manual');
});

it('backfills when an item is created and when it is edited, only from the effective date on', function () {
    [$user, $account] = fhAmSetup();
    FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-06', 'description' => 'Landlord']);

    $this->actingAs($user)->post('/finance/recurring', [
        'name' => 'Rent', 'amount' => '450,00', 'account_id' => $account->id, 'starts_on' => '2027-03-05',
        'recurrence' => ['kind' => 'monthly_day'], 'window_days' => 5, 'tolerance' => '',
    ])->assertStatus(303);
    $created = FinanceRecurring::query()->firstOrFail();
    expect(FinanceRecurringMatch::query()->where('recurring_id', $created->id)->count())->toBe(1);

    // An item with an unpaid past occurrence: an amount edit moves the effective date to today, so March 5 is gone.
    $edited = fhAmRent($account, ['name' => 'Gym', 'expected_amount_minor' => 1000], '2027-03-05');
    FinanceTransaction::factory()->onAccount($account)->expense(2000)->create(['booked_on' => '2027-03-06', 'description' => 'Gym fee']);
    $this->actingAs($user)->patch('/finance/recurring/'.$edited->id, ['amount' => '20,00'])->assertStatus(303);
    expect(FinanceRecurringMatch::query()->where('recurring_id', $edited->id)->count())->toBe(0);

    // A name-only edit keeps the schedule, so an existing exact payment is picked up by the backfill.
    $named = fhAmRent($account, ['name' => 'Water', 'expected_amount_minor' => 3000], '2027-03-05');
    FinanceTransaction::factory()->onAccount($account)->expense(3000)->create(['booked_on' => '2027-03-04', 'description' => 'Water co']);
    $this->actingAs($user)->patch('/finance/recurring/'.$named->id, ['name' => 'Water bill'])->assertStatus(303);
    expect(FinanceRecurringMatch::query()->where('recurring_id', $named->id)->count())->toBe(1);
});

it('drops the match with a deleted transaction so the occurrence is open again', function () {
    [$user, $account] = fhAmSetup();
    $item = fhAmRent($account);
    fhAmPay($this, $user, $account, '450,00', '2027-03-06');
    expect(fhAmStatus($user, $item, '2027-03-05'))->toBe('paid');

    $tx = FinanceTransaction::query()->firstOrFail();
    $this->actingAs($user)->delete('/finance/transactions/'.$tx->id)->assertStatus(303);

    expect(FinanceRecurringMatch::query()->count())->toBe(0)
        ->and(fhAmStatus($user, $item, '2027-03-05'))->toBe('overdue');
});

it('leaves a hidden account\'s matches, rejections and status untouched when the visible leg of a transfer changes', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create();
    $owner = User::factory()->withPreset(Preset::Parent)->create();
    $a = FinanceAccount::factory()->withMembers($viewer, $owner)->create(['name' => 'Visible']);
    $b = FinanceAccount::factory()->withMembers($owner)->create(['name' => 'ZxCanaryFin Savings']);
    $item = FinanceRecurring::factory()->onAccount($b)->startingOn('2027-03-05')->create(['name' => 'ZxCanaryFin plan', 'expected_amount_minor' => 45000]);

    $transferId = (string) Str::uuid7();
    $x = FinanceTransaction::factory()->onAccount($a)->create(['kind' => 'transfer', 'amount_minor' => 45000, 'transfer_id' => $transferId, 'booked_on' => '2027-03-05', 'description' => 'Move']);
    $y = FinanceTransaction::factory()->onAccount($b)->create(['kind' => 'transfer', 'amount_minor' => -45000, 'transfer_id' => $transferId, 'booked_on' => '2027-03-05', 'description' => 'Move']);
    $stray = FinanceTransaction::factory()->onAccount($b)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Rejected']);
    FinanceRecurringMatch::factory()->between($item, '2027-03-05', $y)->create();
    DB::table('finance_match_rejections')->insert([
        'id' => (string) Str::uuid7(), 'recurring_id' => $item->id, 'account_id' => $b->id, 'due_date' => '2027-03-05',
        'transaction_id' => $stray->id, 'created_at' => now(),
    ]);

    $snapshot = fn () => [
        'matches' => DB::table('finance_recurring_matches')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'rejections' => DB::table('finance_match_rejections')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'bTx' => DB::table('finance_transactions')->where('account_id', $b->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'status' => app(RecurringQuery::class)->bill(Viewer::for($owner), (string) $item->id, '2027-03-05'),
    ];
    $before = $snapshot();
    expect($before['status']['status'])->toBe('paid');

    DB::flushQueryLog();
    DB::enableQueryLog();
    FinancePrivacy::assertScopedMutations(function () use ($viewer, $x) {
        $this->actingAs($viewer)->patch('/finance/transactions/'.$x->id, ['amount' => '460,00', 'booked_on' => '2027-03-08'])->assertStatus(303);
        app('auth')->forgetGuards();
        $this->actingAs($viewer)->delete('/finance/transactions/'.$x->id)->assertStatus(303);
    });
    $statements = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn ($q) => preg_match('/^(select|update|delete)\b/i', $q) === 1)
        ->filter(fn ($q) => preg_match('/finance_(transactions|recurring_matches|match_rejections)/', $q) === 1)
        ->all();
    DB::disableQueryLog();

    expect(FinanceTransaction::query()->whereKey($x->id)->exists())->toBeFalse()
        ->and($snapshot())->toEqual($before)
        ->and(FinancePrivacy::unscopedReads($statements))->toBe([]);
});

it('writes the recurring item\'s account on every match and rejection row and scopes the delete', function () {
    [$user, $account] = fhAmSetup();
    $item = fhAmRent($account);
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-06', 'description' => 'Rent']);
    $service = app(RecurringMatchService::class);
    $viewer = Viewer::for($user);

    $service->matchTransactions($viewer, [(string) $tx->id]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->unmatch($viewer, $item, '2027-03-05');
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    $deletes = array_filter($statements, fn ($q) => str_starts_with(strtolower($q), 'delete'));
    expect($deletes)->not->toBeEmpty();
    foreach ($deletes as $sql) {
        expect($sql)->toContain('finance_account_members');
    }
    expect(DB::table('finance_match_rejections')->value('account_id'))->toBe($account->id);
});

it('never matches on a text hint alone: minimum length, plausible amount, and the date window all apply', function () {
    [$user, $account] = fhAmSetup();

    // A one-character hint matches nothing, even for a transaction in the window that contains it.
    $short = fhAmRent($account, ['text_hint' => 'a', 'name' => 'Short']);
    fhAmPay($this, $user, $account, '480,00', '2027-03-05', 'a shop');
    expect(fhAmStatus($user, $short, '2027-03-05'))->toBe('overdue');

    // A three-character hint is still too short.
    $three = fhAmRent($account, ['text_hint' => 'abc', 'name' => 'Three'], '2027-03-07');
    fhAmPay($this, $user, $account, '480,00', '2027-03-07', 'abc store');
    expect(fhAmStatus($user, $three, '2027-03-07'))->toBe('overdue');

    // A long hint with an implausible amount does not match (3x the expected amount).
    $far = fhAmRent($account, ['text_hint' => 'landlord', 'name' => 'Far'], '2027-03-08');
    fhAmPay($this, $user, $account, '1350,00', '2027-03-08', 'Landlord extra');
    expect(fhAmStatus($user, $far, '2027-03-08'))->toBe('overdue');

    // A long hint outside the date window does not match either.
    $late = fhAmRent($account, ['text_hint' => 'electricity', 'name' => 'Late'], '2027-01-05');
    fhAmPay($this, $user, $account, '480,00', '2027-03-09', 'Electricity bill');
    expect(fhAmStatus($user, $late, '2027-01-05'))->not->toBe('paid')->not->toBe('paid_differently');
});

it('refuses a text hint shorter than four characters when saving a recurring item', function () {
    [$user, $account] = fhAmSetup();
    $this->actingAs($user)->post('/finance/recurring', [
        'name' => 'Rent', 'amount' => '450,00', 'account_id' => $account->id, 'category_id' => FinanceCategory::query()->where('kind', 'expense')->value('id'),
        'recurrence' => ['kind' => 'monthly_day', 'monthDay' => 5], 'starts_on' => '2027-03-05', 'text_hint' => 'ab',
    ])->assertSessionHasErrors(['text_hint' => 'finance.rec.hint_text']);
});
