<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FinancePrivacy;

function fhOvUser(): User
{
    return User::factory()->withPreset(Preset::Parent)->create();
}

function fhOvCat(string $key): string
{
    return FinanceCategory::query()->where('default_key', $key)->firstOrFail()->id;
}

function fhOvGet(User $user, string $query = '')
{
    return test()->actingAs($user)->get('/finance'.$query);
}

it('returns exact per-currency income, spending, month-end balance and a 12 month series', function () {
    $user = fhOvUser();
    $a = FinanceAccount::factory()->withMembers($user)->create(['opening_balance_minor' => 100000]);
    $a2 = FinanceAccount::factory()->withMembers($user)->create(['opening_balance_minor' => 5000]);
    $czk = FinanceAccount::factory()->withMembers($user)->create(['currency' => 'CZK', 'opening_balance_minor' => 0]);
    FinanceTransaction::factory()->onAccount($a)->income(200000)->create(['booked_on' => '2026-03-02', 'description' => 'Pay']);
    FinanceTransaction::factory()->onAccount($a)->expense(12345)->create(['booked_on' => '2026-03-09', 'description' => 'Shop', 'category_id' => fhOvCat('groceries')]);
    FinanceTransaction::factory()->onAccount($a2)->expense(1)->create(['booked_on' => '2026-03-31', 'description' => 'Edge']);
    FinanceTransaction::factory()->onAccount($a)->expense(700)->create(['booked_on' => '2026-04-01', 'description' => 'After']);
    FinanceTransaction::factory()->onAccount($a)->expense(900)->create(['booked_on' => '2025-12-15', 'description' => 'Before']);
    FinanceTransaction::factory()->onAccount($czk)->expense(999999999999)->create(['booked_on' => '2026-03-05', 'description' => 'Big']);

    fhOvGet($user, '?month=2026-03')->assertInertia(fn (Assert $page) => $page
        ->component('Finance/Overview', false)
        ->has('overview.blocks', 2)
        ->where('overview.blocks.0.currency', 'EUR')
        ->where('overview.blocks.0.summary.incomeMinor', '200000')
        ->where('overview.blocks.0.summary.spendingMinor', '12346')
        ->where('overview.blocks.0.summary.balanceMinor', (string) (105000 - 900 + 200000 - 12345 - 1))
        ->where('overview.blocks.1.currency', 'CZK')
        ->where('overview.blocks.1.summary.spendingMinor', '999999999999')
        ->where('overview.blocks.1.summary.balanceMinor', '-999999999999')
        ->has('overview.blocks.0.balanceSeries', 12)
        ->where('overview.blocks.0.balanceSeries.11.month', '2026-03')
        ->where('overview.blocks.0.balanceSeries.0.month', '2025-04')
        ->where('overview.blocks.0.balanceSeries.0.balanceMinor', '105000')
        ->where('overview.blocks.0.balanceSeries.8.balanceMinor', '104100')
        ->where('overview.blocks.0.balanceSeries.9.balanceMinor', '104100')
        ->where('overview.blocks.0.balanceSeries.11.balanceMinor', (string) (105000 - 900 + 200000 - 12345 - 1))
        ->where('overview.hasRecurring', false)
        ->where('overview.blocks.0.stillToPay', null));

    fhOvGet($user, '?month=2026-03&account='.$a->id)->assertInertia(fn (Assert $page) => $page
        ->has('overview.blocks', 1)
        ->where('overview.blocks.0.summary.spendingMinor', '12345')
        ->where('overview.blocks.0.summary.balanceMinor', (string) (100000 - 900 + 200000 - 12345)));
});

it('keeps a negative balance negative and a month without rows at the previous balance', function () {
    $user = fhOvUser();
    $a = FinanceAccount::factory()->withMembers($user)->create(['opening_balance_minor' => 0]);
    FinanceTransaction::factory()->onAccount($a)->expense(5000)->create(['booked_on' => '2026-01-10', 'description' => 'Debt']);

    fhOvGet($user, '?month=2026-03')->assertInertia(fn (Assert $page) => $page
        ->where('overview.blocks.0.balanceSeries.9.balanceMinor', '-5000')
        ->where('overview.blocks.0.balanceSeries.10.balanceMinor', '-5000')
        ->where('overview.blocks.0.balanceSeries.11.balanceMinor', '-5000'));
});

it('lists the top 7 categories plus other and the full table with half-up percents', function () {
    $user = fhOvUser();
    $a = FinanceAccount::factory()->withMembers($user)->create();
    $amounts = [1 => 3000, 2 => 2000, 3 => 1000, 4 => 900, 5 => 800, 6 => 700, 7 => 600, 8 => 500, 9 => 400];
    $keys = FinanceCategory::query()->where('kind', 'expense')->orderBy('id')->limit(8)->pluck('id')->all();
    foreach ($keys as $i => $id) {
        FinanceTransaction::factory()->onAccount($a)->expense($amounts[$i + 1])->create(['booked_on' => '2026-03-03', 'description' => 'c'.$i, 'category_id' => $id]);
    }
    FinanceTransaction::factory()->onAccount($a)->expense($amounts[9])->create(['booked_on' => '2026-03-04', 'description' => 'none', 'category_id' => null]);
    // 9500 total; the first two get 3000 and 2000.
    fhOvGet($user, '?month=2026-03')->assertInertia(fn (Assert $page) => $page
        ->where('overview.blocks.0.categorySpending.totalMinor', '9900')
        ->has('overview.blocks.0.categorySpending.top', 7)
        ->where('overview.blocks.0.categorySpending.top.0.percent', 30)
        ->where('overview.blocks.0.categorySpending.top.1.percent', 20)
        ->where('overview.blocks.0.categorySpending.other.amountMinor', '900')
        ->where('overview.blocks.0.categorySpending.other.percent', 9)
        ->has('overview.blocks.0.categorySpending.all', 9));
});

it('shows blocks [] for a viewer with no visible account and one identical 403 for probed budgets', function () {
    $user = fhOvUser();
    $other = fhOvUser();
    $pln = FinanceAccount::factory()->withMembers($other)->create(['currency' => 'PLN']);
    $hidden = FinanceBudget::factory()->limit(5000)->create(['category_id' => fhOvCat('groceries'), 'currency' => 'PLN', 'effective_from' => '2026-03-01']);
    FinanceTransaction::factory()->onAccount($pln)->expense(5000)->create(['booked_on' => '2026-03-03', 'description' => 'x']);

    fhOvGet($user, '?month=2026-03')->assertInertia(fn (Assert $page) => $page->where('overview.blocks', []));

    FinanceAccount::factory()->withMembers($user)->create();
    $a = fhOvGet($user, '?budget='.$hidden->id);
    $b = fhOvGet($user, '?budget='.Str::uuid());
    $a->assertForbidden();
    expect(FinancePrivacy::normalise($a))->toBe(FinancePrivacy::normalise($b));
    fhOvGet($user, '?budget=not-a-uuid')->assertForbidden();
});

it('renders the budget panel for a visible budget and refuses new without edit', function () {
    $user = fhOvUser();
    FinanceAccount::factory()->withMembers($user)->create();
    $budget = FinanceBudget::factory()->limit(40000)->create(['category_id' => fhOvCat('groceries'), 'currency' => 'EUR', 'effective_from' => '2026-03-01']);

    fhOvGet($user, '?month=2026-03&budget='.$budget->id)->assertInertia(fn (Assert $page) => $page
        ->where('panelBudget.id', (string) $budget->id)->where('panelBudget.limitMinor', '40000'));
    fhOvGet($user, '?budget=new')->assertInertia(fn (Assert $page) => $page
        ->where('panelBudget.mode', 'new')->where('panelBudget.currency', 'EUR'));

    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    FinanceAccount::query()->firstOrFail()->members()->attach($viewOnly->id, ['created_at' => now()]);
    fhOvGet($viewOnly, '?budget=new')->assertForbidden();
});

it('carries still to pay per currency only with visible recurring items', function () {
    $user = fhOvUser();
    $a = FinanceAccount::factory()->withMembers($user)->create();
    FinanceRecurring::factory()->onAccount($a)->startingOn(now(config('family.timezone'))->toDateString())
        ->create(['name' => 'Rent', 'expected_amount_minor' => 45000]);

    fhOvGet($user)->assertInertia(fn (Assert $page) => $page
        ->where('overview.hasRecurring', true)
        ->where('overview.blocks.0.stillToPay.currency', 'EUR'));
});
