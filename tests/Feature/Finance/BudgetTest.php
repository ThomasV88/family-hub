<?php

use App\Access\Preset;
use App\Finance\Queries\BudgetQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fhBudgetCat(string $key = 'groceries'): FinanceCategory
{
    return FinanceCategory::query()->where('default_key', $key)->firstOrFail();
}

/** @return array{0: User, 1: FinanceAccount} */
function fhBudgetSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();

    return [$user, FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main'])];
}

function fhBudgetRows(User $user, string $month, ?string $account = null): array
{
    return app(BudgetQuery::class)->forMonth(Viewer::for($user), $month, $account);
}

function fhSpend(FinanceAccount $account, int $minor, string $date = '2026-03-10', ?FinanceCategory $category = null): void
{
    FinanceTransaction::factory()->onAccount($account)->expense($minor)
        ->create(['booked_on' => $date, 'description' => 'Spend', 'category_id' => ($category ?? fhBudgetCat())->id]);
}

it('versions a limit by month: carries forward, changes from a month, ends from a month', function () {
    [$user] = fhBudgetSetup();
    $cat = fhBudgetCat();

    $this->actingAs($user)->post('/finance/budgets', ['category_id' => $cat->id, 'currency' => 'EUR', 'amount' => '400,00', 'from_month' => '2026-03'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.budget_saved');
    $id = FinanceBudget::query()->firstOrFail()->id;

    $this->actingAs($user)->patch('/finance/budgets/'.$id, ['amount' => '500,00', 'from_month' => '2026-05'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.budget_saved');
    $this->actingAs($user)->delete('/finance/budgets/'.$id, ['from_month' => '2026-06'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.budget_removed');

    $limit = fn (string $m) => fhBudgetRows($user, $m)['EUR'][0]['limitMinor'] ?? null;
    expect($limit('2026-02'))->toBeNull()
        ->and($limit('2026-03'))->toBe('40000')
        ->and($limit('2026-04'))->toBe('40000')
        ->and($limit('2026-05'))->toBe('50000')
        ->and($limit('2026-06'))->toBeNull()
        ->and($limit('2026-09'))->toBeNull()
        ->and(fhBudgetRows($user, '2026-04')['EUR'][0]['effectiveFrom'])->toBe('2026-03')
        ->and(FinanceBudget::query()->count())->toBe(3);
});

it('deletes the first version outright and writes a tombstone otherwise', function () {
    [$user] = fhBudgetSetup();
    $cat = fhBudgetCat();
    $first = FinanceBudget::factory()->limit(1000)->create(['category_id' => $cat->id, 'effective_from' => '2026-03-01']);

    $this->actingAs($user)->delete('/finance/budgets/'.$first->id, ['from_month' => '2026-03'])->assertStatus(303);
    expect(FinanceBudget::query()->count())->toBe(0);

    $a = FinanceBudget::factory()->limit(1000)->create(['category_id' => $cat->id, 'effective_from' => '2026-03-01']);
    $this->actingAs($user)->delete('/finance/budgets/'.$a->id, ['from_month' => '2026-04'])->assertStatus(303);
    expect(FinanceBudget::query()->whereNull('amount_minor')->count())->toBe(1);
});

it('computes percent, near, over and the over amount on the server', function () {
    [$user, $account] = fhBudgetSetup();
    FinanceBudget::factory()->limit(40000)->create(['category_id' => fhBudgetCat()->id, 'effective_from' => '2026-03-01']);

    $row = fn () => fhBudgetRows($user, '2026-03')['EUR'][0];

    expect($row())->toMatchArray(['spentMinor' => '0', 'percent' => 0, 'state' => 'ok', 'overMinor' => null]);

    fhSpend($account, 35999);
    expect($row())->toMatchArray(['percent' => 89, 'state' => 'ok']);

    fhSpend($account, 1);
    expect($row())->toMatchArray(['spentMinor' => '36000', 'percent' => 90, 'state' => 'near', 'overMinor' => null]);

    fhSpend($account, 4000);
    expect($row())->toMatchArray(['spentMinor' => '40000', 'percent' => 100, 'state' => 'near', 'overMinor' => null]);

    fhSpend($account, 1);
    expect($row())->toMatchArray(['spentMinor' => '40001', 'percent' => 100, 'state' => 'over', 'overMinor' => '1']);

    fhSpend($account, 100, '2026-04-01');
    fhSpend($account, 100, '2026-03-10', fhBudgetCat('transport'));
    expect($row()['spentMinor'])->toBe('40001');
});

it('counts only visible accounts and the selected account', function () {
    [$user, $account] = fhBudgetSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $second = FinanceAccount::factory()->withMembers($user)->create();
    FinanceBudget::factory()->limit(40000)->create(['category_id' => fhBudgetCat()->id, 'effective_from' => '2026-03-01']);

    fhSpend($account, 1000);
    fhSpend($second, 2000);
    fhSpend($hidden, 777777);

    expect(fhBudgetRows($user, '2026-03')['EUR'][0]['spentMinor'])->toBe('3000')
        ->and(fhBudgetRows($user, '2026-03', (string) $account->id)['EUR'][0]['spentMinor'])->toBe('1000')
        ->and(fhBudgetRows($user, '2026-03', (string) $second->id)['EUR'][0]['spentMinor'])->toBe('2000')
        ->and(fhBudgetRows($user, '2026-03', (string) $hidden->id))->toBe([]);
});

it('ignores fully visible transfers and counts a leg with a hidden counterpart as spending', function () {
    [$user, $account] = fhBudgetSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $second = FinanceAccount::factory()->withMembers($user)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    FinanceBudget::factory()->limit(40000)->create(['category_id' => fhBudgetCat()->id, 'effective_from' => '2026-03-01']);

    $leg = function (FinanceAccount $a, int $minor, string $transferId) {
        FinanceTransaction::factory()->onAccount($a)->create([
            'kind' => 'transfer', 'amount_minor' => $minor, 'transfer_id' => $transferId, 'booked_on' => '2026-03-10',
            'description' => 'Move', 'category_id' => fhBudgetCat()->id,
        ]);
    };
    $t1 = (string) Str::uuid7();
    $leg($account, -5000, $t1);
    $leg($second, 5000, $t1);
    expect(fhBudgetRows($user, '2026-03')['EUR'][0]['spentMinor'])->toBe('0');

    $t2 = (string) Str::uuid7();
    $leg($account, -2500, $t2);
    $leg($hidden, 2500, $t2);
    expect(fhBudgetRows($user, '2026-03')['EUR'][0]['spentMinor'])->toBe('2500');

    // The other member sees A and the hidden account but not the second one: only the A -> second leg counts.
    $account->members()->attach($other->id, ['created_at' => now()]);
    expect(fhBudgetRows($other, '2026-03')['EUR'][0]['spentMinor'])->toBe('5000');
});

it('limits budgets to the currencies of the viewer visible accounts', function () {
    [$user] = fhBudgetSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    FinanceAccount::factory()->withMembers($other)->create(['currency' => 'PLN']);
    $pln = FinanceBudget::factory()->limit(5000)->create(['category_id' => fhBudgetCat()->id, 'currency' => 'PLN', 'effective_from' => '2026-03-01']);
    $viewer = Viewer::for($user);
    $query = app(BudgetQuery::class);

    expect(fhBudgetRows($user, '2026-03'))->toBe([])
        ->and($query->find($viewer, (string) $pln->id))->toBeNull()
        ->and($query->find($viewer, 'not-a-uuid'))->toBeNull();

    $query->upsertVersion($viewer, (string) $pln->category_id, 'PLN', '2026-03', 9999);
    $query->upsertVersion($viewer, (string) $pln->category_id, 'PLN', '2026-07', 9999);
    expect($query->deleteVersion($viewer, (string) $pln->id))->toBe(0)
        ->and($pln->fresh()->amount_minor)->toBe(5000)
        ->and(FinanceBudget::query()->count())->toBe(1);
});

it('validates creation input', function () {
    [$user] = fhBudgetSetup();
    $cat = fhBudgetCat();
    $ok = ['category_id' => $cat->id, 'currency' => 'EUR', 'amount' => '400,00', 'from_month' => '2026-03'];

    $this->actingAs($user)->post('/finance/budgets', $ok)->assertStatus(303);
    $this->actingAs($user)->post('/finance/budgets', $ok)->assertSessionHasErrors(['category_id' => 'finance.budget.err_taken']);
    $this->actingAs($user)->post('/finance/budgets', ['from_month' => '2026-08'] + $ok)->assertSessionHasErrors(['category_id' => 'finance.budget.err_taken']);
    $this->actingAs($user)->post('/finance/budgets', ['currency' => 'PLN'] + $ok)->assertSessionHasErrors(['currency' => 'finance.error.account']);
    $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('salary')->id] + $ok)->assertSessionHasErrors(['category_id' => 'finance.error.category']);
    $this->actingAs($user)->post('/finance/budgets', ['category_id' => (string) Str::uuid7()] + $ok)->assertSessionHasErrors(['category_id' => 'finance.error.category']);
    $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('transport')->id, 'amount' => '0'] + $ok)->assertSessionHasErrors(['amount' => 'finance.budget.err_amount']);
    $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('transport')->id, 'amount' => '100000000000,01'] + $ok)->assertSessionHasErrors(['amount' => 'finance.budget.err_amount']);
    $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('transport')->id, 'amount' => 'abc'] + $ok)->assertSessionHasErrors(['amount' => 'finance.budget.err_amount']);
    foreach (['2026-13', '2026-3', '', 'x'] as $month) {
        $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('transport')->id, 'from_month' => $month] + $ok)->assertSessionHasErrors(['from_month' => 'finance.error.month']);
    }
    expect(FinanceBudget::query()->count())->toBe(1);
});

it('lets a create succeed again after the budget was ended', function () {
    [$user] = fhBudgetSetup();
    $cat = fhBudgetCat();
    $ok = ['category_id' => $cat->id, 'currency' => 'EUR', 'amount' => '400,00', 'from_month' => '2026-03'];

    $this->actingAs($user)->post('/finance/budgets', $ok)->assertStatus(303);
    $id = FinanceBudget::query()->firstOrFail()->id;
    $this->actingAs($user)->delete('/finance/budgets/'.$id, ['from_month' => '2026-05'])->assertStatus(303);

    $this->actingAs($user)->post('/finance/budgets', ['from_month' => '2026-06', 'amount' => '250,00'] + $ok)->assertStatus(303)->assertSessionHasNoErrors();
    expect(fhBudgetRows($user, '2026-06')['EUR'][0]['limitMinor'])->toBe('25000');
});

it('answers a budget in an invisible currency exactly like an unknown budget', function () {
    [$user] = fhBudgetSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    FinanceAccount::factory()->withMembers($other)->create(['currency' => 'PLN']);
    $pln = FinanceBudget::factory()->limit(5000)->create(['category_id' => fhBudgetCat()->id, 'currency' => 'PLN']);

    foreach ([['patch', ['amount' => '1,00', 'from_month' => '2026-03']], ['delete', ['from_month' => '2026-03']]] as [$method, $data]) {
        $hidden = $this->actingAs($user)->{$method}('/finance/budgets/'.$pln->id, $data);
        $random = $this->actingAs($user)->{$method}('/finance/budgets/'.Str::uuid7(), $data);
        expect($hidden->getStatusCode())->toBe(404)->and($random->getStatusCode())->toBe(404)
            ->and(FinancePrivacy::normalise($hidden))->toBe(FinancePrivacy::normalise($random));
    }
    expect($pln->fresh()->amount_minor)->toBe(5000);
});

it('issues only membership-scoped mutation statements', function () {
    [$user] = fhBudgetSetup();
    $cat = fhBudgetCat();
    $budget = FinanceBudget::factory()->limit(1000)->create(['category_id' => $cat->id, 'effective_from' => '2026-02-01']);

    FinancePrivacy::assertScopedMutations(function () use ($user, $budget) {
        $this->actingAs($user)->post('/finance/budgets', ['category_id' => fhBudgetCat('transport')->id, 'currency' => 'EUR', 'amount' => '1,00', 'from_month' => '2026-03'])->assertStatus(303);
        $this->actingAs($user)->patch('/finance/budgets/'.$budget->id, ['amount' => '2,00', 'from_month' => '2026-02'])->assertStatus(303);
        $this->actingAs($user)->patch('/finance/budgets/'.$budget->id, ['amount' => '3,00', 'from_month' => '2026-04'])->assertStatus(303);
        $this->actingAs($user)->delete('/finance/budgets/'.$budget->id, ['from_month' => '2026-06'])->assertStatus(303);
        $this->actingAs($user)->delete('/finance/budgets/'.$budget->id, ['from_month' => '2026-02'])->assertStatus(303);
    });
    expect(DB::table('finance_budgets')->where('category_id', $cat->id)->count())->toBe(2);
});

it('enforces the database checks on a budget row', function () {
    $cat = fhBudgetCat();
    $insert = fn (array $over) => fn () => DB::table('finance_budgets')->insert($over + [
        'id' => (string) Str::uuid7(), 'category_id' => $cat->id, 'currency' => 'EUR', 'amount_minor' => 100,
        'effective_from' => '2026-03-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($insert(['effective_from' => '2026-03-02']))->toThrow(QueryException::class)
        ->and($insert(['amount_minor' => 0]))->toThrow(QueryException::class)
        ->and($insert(['currency' => 'eur']))->toThrow(QueryException::class);
});
