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
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fhCatDefault(string $key): FinanceCategory
{
    return FinanceCategory::query()->where('default_key', $key)->firstOrFail();
}

function fhCatUser(): User
{
    return User::factory()->withPreset(Preset::Parent)->create();
}

function fhCatInertia($test, User $user, string $url = '/finance/categories')
{
    return $test->actingAs($user)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()]);
}

/** @return array<string, mixed> the raw row, every column */
function fhCatRow(string $table, string $id): array
{
    return (array) DB::table($table)->where('id', $id)->first();
}

it('lists the family categories and fills the panel from ?cat=', function () {
    $user = fhCatUser();
    $custom = FinanceCategory::factory()->create(['name' => 'Pets']);

    $page = fhCatInertia($this, $user)->assertOk()->json('props');
    expect($page['finance']['categories'])->toHaveCount(12)
        ->and($page['panelCategory'])->toBeNull();

    $panel = fhCatInertia($this, $user, '/finance/categories?cat='.$custom->id)->json('props.panelCategory');
    expect($panel)->toBe(['id' => $custom->id, 'kind' => 'expense', 'defaultKey' => null, 'name' => 'Pets']);
    expect(fhCatInertia($this, $user, '/finance/categories?cat=new')->json('props.panelCategory'))->toBe(['mode' => 'new']);

    $this->actingAs($user)->get('/finance/categories?cat='.Str::uuid7())->assertForbidden();
    $this->actingAs($user)->get('/finance/categories?cat=nonsense')->assertForbidden();

    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $this->actingAs($viewOnly)->get('/finance/categories')->assertOk();
    $this->actingAs($viewOnly)->get('/finance/categories?cat=new')->assertForbidden();
    $this->actingAs($viewOnly)->post('/finance/categories', ['name' => 'X', 'kind' => 'expense'])->assertForbidden();
    $this->actingAs($viewOnly)->patch('/finance/categories/'.$custom->id, ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewOnly)->delete('/finance/categories/'.$custom->id, ['move_to' => fhCatDefault('other')->id])->assertForbidden();
});

it('creates a category and rejects duplicates, empty and over-long names', function () {
    $user = fhCatUser();

    $this->actingAs($user)->post('/finance/categories', ['name' => '  Pets ', 'kind' => 'expense'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.cat_saved');
    expect(FinanceCategory::query()->where('name', 'Pets')->where('kind', 'expense')->whereNull('default_key')->count())->toBe(1);

    $this->actingAs($user)->post('/finance/categories', ['name' => 'PETS', 'kind' => 'expense'])
        ->assertSessionHasErrors(['name' => 'finance.cat.err_taken']);
    $this->actingAs($user)->post('/finance/categories', ['name' => 'pets', 'kind' => 'income'])->assertStatus(303);
    $this->actingAs($user)->post('/finance/categories', ['name' => '', 'kind' => 'expense'])
        ->assertSessionHasErrors(['name' => 'finance.cat.err_name']);
    $this->actingAs($user)->post('/finance/categories', ['name' => str_repeat('a', 41), 'kind' => 'expense'])
        ->assertSessionHasErrors('name');
    $this->actingAs($user)->post('/finance/categories', ['name' => 'Fine', 'kind' => 'other'])->assertSessionHasErrors('kind');
    expect(FinanceCategory::query()->count())->toBe(11 + 2);
});

it('renames a default category and ignores a kind in the payload', function () {
    $user = fhCatUser();
    $groceries = fhCatDefault('groceries');

    $this->actingAs($user)->patch('/finance/categories/'.$groceries->id, ['name' => 'Food', 'kind' => 'income'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.cat_saved');
    $fresh = $groceries->fresh();
    expect($fresh->name)->toBe('Food')->and($fresh->default_key)->toBe('groceries')->and($fresh->kind)->toBe('expense');

    $pets = FinanceCategory::factory()->create(['name' => 'Pets']);
    $this->actingAs($user)->patch('/finance/categories/'.$pets->id, ['name' => 'food'])->assertSessionHasErrors(['name' => 'finance.cat.err_taken']);
    $this->actingAs($user)->patch('/finance/categories/'.$pets->id, ['name' => 'PETS'])->assertStatus(303);
    $this->actingAs($user)->patch('/finance/categories/'.Str::uuid7(), ['name' => 'x'])->assertNotFound();
});

it('deletes with a move target, moving transactions, recurring items and budgets', function () {
    $user = fhCatUser();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    $from = FinanceCategory::factory()->create(['name' => 'Pets']);
    $to = fhCatDefault('other');
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['category_id' => $from->id]);
    $rec = FinanceRecurring::factory()->onAccount($account)->create(['category_id' => $from->id]);
    $clash = FinanceBudget::factory()->limit(111)->create(['category_id' => $from->id, 'currency' => 'EUR', 'effective_from' => '2026-03-01']);
    FinanceBudget::factory()->limit(222)->create(['category_id' => $to->id, 'currency' => 'EUR', 'effective_from' => '2026-03-01']);
    $moved = FinanceBudget::factory()->limit(333)->create(['category_id' => $from->id, 'currency' => 'EUR', 'effective_from' => '2026-05-01']);

    $this->actingAs($user)->delete('/finance/categories/'.$from->id, ['move_to' => $to->id])
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.cat_deleted');

    expect(FinanceCategory::query()->whereKey($from->id)->exists())->toBeFalse()
        ->and($tx->fresh()->category_id)->toBe($to->id)
        ->and($rec->fresh()->category_id)->toBe($to->id)
        ->and(FinanceBudget::query()->whereKey($clash->id)->exists())->toBeFalse()
        ->and($moved->fresh()->category_id)->toBe($to->id)
        ->and(FinanceBudget::query()->where('category_id', $to->id)->where('effective_from', '2026-03-01')->value('amount_minor'))->toBe(222);
});

it('rejects a move target of the other kind, itself, missing or unknown', function () {
    $user = fhCatUser();
    $from = FinanceCategory::factory()->create(['name' => 'Pets']);

    foreach ([fhCatDefault('salary')->id, $from->id, (string) Str::uuid7(), 'nonsense'] as $target) {
        $this->actingAs($user)->delete('/finance/categories/'.$from->id, ['move_to' => $target])
            ->assertSessionHasErrors(['move_to' => 'finance.cat.err_move']);
    }
    $this->actingAs($user)->delete('/finance/categories/'.$from->id)->assertSessionHasErrors(['move_to' => 'finance.cat.err_move']);
    expect(FinanceCategory::query()->whereKey($from->id)->exists())->toBeTrue();
});

it('keeps edited_at and every other column of moved rows, including on hidden accounts', function () {
    $user = fhCatUser();
    $other = fhCatUser();
    $a = FinanceAccount::factory()->withMembers($user, $other)->create();
    $b = FinanceAccount::factory()->withMembers($other)->create();
    $from = FinanceCategory::factory()->create(['name' => 'Pets']);
    $to = fhCatDefault('other');
    $edited = now()->subDays(3)->startOfSecond();

    $rows = [
        'finance_transactions' => [
            FinanceTransaction::factory()->onAccount($a)->expense(500)->create(['category_id' => $from->id, 'edited_at' => null])->id,
            FinanceTransaction::factory()->onAccount($a)->expense(600)->create(['category_id' => $from->id, 'edited_at' => $edited])->id,
            FinanceTransaction::factory()->onAccount($b)->expense(700)->create(['category_id' => $from->id, 'edited_at' => null])->id,
            FinanceTransaction::factory()->onAccount($b)->expense(800)->create(['category_id' => $from->id, 'edited_at' => $edited])->id,
        ],
        'finance_recurring' => [
            FinanceRecurring::factory()->onAccount($a)->create(['category_id' => $from->id])->id,
            FinanceRecurring::factory()->onAccount($b)->create(['category_id' => $from->id])->id,
        ],
        'finance_budgets' => [
            FinanceBudget::factory()->limit(100)->create(['category_id' => $from->id, 'currency' => 'PLN'])->id,
        ],
    ];
    // Make updated_at old so a stray touch would be visible.
    foreach ($rows as $table => $ids) {
        DB::table($table)->whereIn('id', $ids)->update(['updated_at' => now()->subDays(10)]);
    }
    $before = [];
    foreach ($rows as $table => $ids) {
        foreach ($ids as $id) {
            $before[$table][$id] = fhCatRow($table, $id);
        }
    }

    $this->actingAs($user)->delete('/finance/categories/'.$from->id, ['move_to' => $to->id])->assertStatus(303);

    foreach ($before as $table => $byId) {
        foreach ($byId as $id => $old) {
            $new = fhCatRow($table, $id);
            expect($new['category_id'])->toBe($to->id);
            unset($old['category_id'], $new['category_id']);
            expect($new)->toBe($old, "$table $id changed more than category_id");
        }
    }
});

it('leaves moved imported rows deletable by a later import undo', function () {
    $user = fhCatUser();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    $from = FinanceCategory::factory()->create(['name' => 'Pets']);
    $batch = (string) Str::uuid7();
    DB::table('finance_import_batches')->insert([
        'id' => $batch, 'account_id' => $account->id, 'user_id' => $user->id, 'status' => 'committed',
        'row_count' => 1, 'added_count' => 1, 'matched_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $untouched = FinanceTransaction::factory()->onAccount($account)->expense(500)
        ->create(['category_id' => $from->id, 'source' => 'import', 'edited_at' => null]);
    DB::table('finance_transactions')->where('id', $untouched->id)->update(['import_batch_id' => $batch]);

    $this->actingAs($user)->delete('/finance/categories/'.$from->id, ['move_to' => fhCatDefault('other')->id])->assertStatus(303);

    // The undo rule of the import slice: edited_at IS NULL and not a transfer leg.
    $undoable = DB::table('finance_transactions')->where('import_batch_id', $batch)->whereNull('edited_at')->whereNull('transfer_id');
    expect($undoable->count())->toBe(1);
    $undoable->delete();
    expect(FinanceTransaction::query()->whereKey($untouched->id)->exists())->toBeFalse();
});

/**
 * @return array{0: User, 1: string, 2: string} viewer, category to change, move target
 */
function fhCatFamily(bool $hiddenUsage): array
{
    $viewer = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Vera Viewer', 'email' => 'vera@example.test']);
    $other = fhCatUser();
    $a = FinanceAccount::factory()->withMembers($viewer, $other)->create(['name' => 'Visible Account']);
    $b = FinanceAccount::factory()->withMembers($other)->create(['name' => 'ZxCanaryFin Hidden', 'currency' => 'PLN']);
    $cat = FinanceCategory::factory()->create(['name' => 'Pets']);
    FinanceTransaction::factory()->onAccount($a)->expense(1200)->create(['category_id' => $cat->id, 'description' => 'Visible vet', 'booked_on' => '2026-03-02']);
    if ($hiddenUsage) {
        FinanceTransaction::factory()->onAccount($b)->expense(777777)->create(['category_id' => $cat->id, 'description' => 'ZxCanaryFin vet', 'booked_on' => '2026-03-03']);
        FinanceRecurring::factory()->onAccount($b)->create(['category_id' => $cat->id, 'name' => 'ZxCanaryFin insurance']);
        FinanceBudget::factory()->limit(777777)->create(['category_id' => $cat->id, 'currency' => 'PLN']);
    }

    return [$viewer, (string) $cat->id, fhCatDefault('other')->id];
}

/** Normalises per-request noise and ids that differ between the two families. */
function fhCatShape(string $body, string $categoryId, string $targetId): string
{
    $shaped = str_replace([$categoryId], ['CAT'], FinancePrivacy::scannable($body));
    // Row ids and the per-request auth user differ between the two families by construction.
    $shaped = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'UUID', $shaped) ?? $shaped;
    $shaped = preg_replace('/"user":\{[^}]*\}/', '"user":{}', $shaped) ?? $shaped;

    return $shaped;
}

it('answers PATCH and DELETE byte-identically with and without hidden usage', function (string $route) {
    $outputs = [];
    foreach ([false, true] as $hidden) {
        DB::beginTransaction();
        [$viewer, $cat, $target] = fhCatFamily($hidden);
        app('auth')->forgetGuards();
        $this->flushSession();
        $response = $route === 'patch'
            ? $this->actingAs($viewer)->patch('/finance/categories/'.$cat, ['name' => 'Animals'])
            : $this->actingAs($viewer)->delete('/finance/categories/'.$cat, ['move_to' => $target]);
        $write = fhCatShape(FinancePrivacy::normalise($response).'|'.json_encode(session('toast')), $cat, $target);

        app('auth')->forgetGuards();
        $this->flushSession();
        $inertia = $this->actingAs($viewer)->get('/finance/categories', ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()]);
        $html = $this->actingAs($viewer)->get('/finance/categories');
        $outputs[] = [
            $write,
            fhCatShape(FinancePrivacy::normalise($inertia), $cat, $target),
            fhCatShape(FinancePrivacy::normalise($html), $cat, $target),
        ];
        DB::rollBack();
    }

    expect($outputs[1])->toBe($outputs[0]);
    expect($outputs[0][0])->not->toContain('ZxCanaryFin');
})->with(['patch', 'delete']);

it('audits a category delete as one content-free entry', function () {
    $user = fhCatUser();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Hidden Savings']);
    $from = FinanceCategory::factory()->create(['name' => 'Pets']);
    FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['category_id' => $from->id]);

    $this->actingAs($user)->delete('/finance/categories/'.$from->id, ['move_to' => fhCatDefault('other')->id])->assertStatus(303);

    $rows = \App\Models\AuditEntry::query()->where('event', 'finance_category_changed')->get();
    expect($rows)->toHaveCount(1)
        ->and((array) $rows->first()->meta)->toBe(['change' => 'deleted'])
        ->and($rows->first()->actor_id)->toBe($user->id)
        ->and($rows->first()->module)->toBe('finance');
    $json = json_encode($rows->first()->toArray());
    expect($json)->not->toContain('Pets')->not->toContain('Hidden Savings')->not->toContain($account->id);
});
