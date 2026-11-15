<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use App\Models\FinanceRecurring;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fhAdminUser(?AccessLevel $finance = null): User
{
    $u = User::factory()->admin();
    if ($finance !== null) {
        $u = $u->withGrant(Module::Finance, $finance);
    }

    return $u->create();
}

function fhParent(string $name = 'Pat Parent'): User
{
    return User::factory()->withPreset(Preset::Parent)->create(['name' => $name]);
}

function fhStore(array $override = []): array
{
    return array_merge(['name' => 'Joint', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '', 'balance_as_of' => '2026-01-01', 'member_ids' => []], $override);
}

it('lists metadata for the admin with nothing money-shaped for accounts they are not ticked on', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $parent = fhParent();
    $shared = FinanceAccount::factory()->withMembers($parent)->create(['name' => 'Hidden from admin', 'opening_balance_minor' => 987654]);
    FinanceTransaction::factory()->onAccount($shared)->expense(500)->create();

    $this->actingAs($admin)->get('/settings/finance-accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/FinanceAccounts')
        ->has('accounts', 1)
        ->where('accounts.0.memberIds', [$parent->id])
        ->where('accounts.0.memberCount', 1)
        ->where('accounts.0.sharedWithMe', false)
        ->where('accounts.0.opening', null)
        ->has('members', 2)
        ->where('panel', null)
        ->has('sections', 8));

    $json = $this->actingAs($admin)->get('/settings/finance-accounts', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])->getContent();
    expect($json)->not->toContain('987654')->not->toContain('9876');
});

it('creates an account with exact negative opening, UUID id and exactly the ticked members', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $parent = fhParent();

    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['opening_balance' => '−1 234,50', 'member_ids' => [$parent->id]]))
        ->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.acc_saved');

    $account = FinanceAccount::query()->first();
    expect(Str::isUuid($account->id))->toBeTrue()
        ->and($account->opening_balance_minor)->toBe(-123450)
        ->and($account->created_by)->toBe($admin->id)
        ->and(DB::table('finance_account_members')->where('account_id', $account->id)->pluck('user_id')->all())->toBe([$parent->id]);
});

it('starts shared with nobody and does not add the creating admin', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore())->assertStatus(303);

    expect(DB::table('finance_account_members')->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'finance_visibility_changed')->count())->toBe(0);
});

it('stores a typed zero opening balance as zero and rejects garbage', function () {
    $admin = fhAdminUser();
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['opening_balance' => '0,00']))->assertStatus(303);
    expect(FinanceAccount::query()->first()->opening_balance_minor)->toBe(0);

    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['opening_balance' => 'abc']))->assertSessionHasErrors(['opening_balance' => 'finance.error.amount']);
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['name' => '']))->assertSessionHasErrors(['name' => 'finance.acc.err_name']);
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['currency' => 'JPY']))->assertSessionHasErrors('currency');
});

it('refuses ticking a member without finance access and changes nothing', function () {
    $admin = fhAdminUser();
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['member_ids' => [$child->id]]))
        ->assertSessionHasErrors(['member_ids.0' => 'finance.error.members']);
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['member_ids' => [999999]]))
        ->assertSessionHasErrors(['member_ids.0' => 'finance.error.members']);

    expect(FinanceAccount::query()->count())->toBe(0);
});

it('audits each share and unshare as its own content-free entry', function () {
    $admin = fhAdminUser();
    $a = fhParent('Anna');
    $b = fhParent('Boris');
    $c = fhParent('Cyril');
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['name' => 'Secret Savings', 'member_ids' => [$a->id, $b->id]]))->assertStatus(303);
    $account = FinanceAccount::query()->first();

    $rows = AuditEntry::query()->where('event', 'finance_visibility_changed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('meta')->map(fn ($m) => (array) $m)->all())->toBe([['change' => 'added', 'account' => FinanceAccount::query()->value('id')], ['change' => 'added', 'account' => FinanceAccount::query()->value('id')]])
        ->and($rows->pluck('target_user_id')->all())->toBe([$a->id, $b->id])
        ->and($rows->first()->actor_id)->toBe($admin->id)
        ->and($rows->first()->module)->toBe('finance');

    // keep Anna, drop Boris, add Cyril
    $this->actingAs($admin)->patch("/settings/finance-accounts/{$account->id}", ['name' => 'Secret Savings', 'type' => 'bank', 'member_ids' => [$a->id, $c->id]])->assertStatus(303);

    $new = AuditEntry::query()->where('event', 'finance_visibility_changed')->orderBy('id')->get()->slice(2)->values();
    expect($new)->toHaveCount(2);
    $byTarget = $new->mapWithKeys(fn ($r) => [$r->target_user_id => ((array) $r->meta)['change']])->all();
    expect($byTarget)->toBe([$c->id => 'added', $b->id => 'removed']);

    // The only account reference is the opaque public id inside the allowlisted `account` meta key; no names.
    foreach (AuditEntry::query()->get() as $row) {
        expect(json_encode($row->toArray()))->not->toContain('Secret Savings');
        if ($row->meta !== null) {
            expect(array_keys((array) $row->meta))->each->toBeIn(['change', 'account']);
        }
    }
    expect($new->every(fn ($r) => ((array) $r->meta)['account'] === $account->id))->toBeTrue();
});

it('audits an admin adding themselves to an account as a distinct event naming the account id', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $a = fhParent('Anna');
    $this->actingAs($admin)->post('/settings/finance-accounts', fhStore(['name' => 'Secret Savings', 'member_ids' => [$a->id, $admin->id]]))->assertStatus(303);
    $account = FinanceAccount::query()->first();

    $self = AuditEntry::query()->where('event', 'finance_admin_self_added')->get();
    expect($self)->toHaveCount(1)
        ->and((array) $self->first()->meta)->toBe(['account' => $account->id])
        ->and($self->first()->target_user_id)->toBe($admin->id)
        ->and(AuditEntry::query()->where('event', 'finance_visibility_changed')->where('target_user_id', $admin->id)->count())->toBe(0);

    // Ticking themselves on an existing account is the same distinct event.
    $other = FinanceAccount::factory()->withMembers($a)->create();
    $this->actingAs($admin)->patch("/settings/finance-accounts/{$other->id}", ['name' => $other->name, 'type' => 'bank', 'member_ids' => [$a->id, $admin->id]])->assertStatus(303);
    expect(AuditEntry::query()->where('event', 'finance_admin_self_added')->get()->map(fn ($r) => ((array) $r->meta)['account'])->all())->toBe([$account->id, $other->id]);
});

it('gives an unticked admin no opening data and prohibits money fields identically with or without transactions', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $parent = fhParent();
    $empty = FinanceAccount::factory()->withMembers($parent)->create();
    $busy = FinanceAccount::factory()->withMembers($parent)->create();
    FinanceTransaction::factory()->onAccount($busy)->expense(100)->create();

    $bodies = [];
    foreach (['currency' => 'PLN', 'opening_balance' => '5', 'balance_as_of' => '2026-05-01'] as $field => $value) {
        foreach ([$empty, $busy] as $i => $account) {
            $response = $this->actingAs($admin)->patch("/settings/finance-accounts/{$account->id}", ['name' => 'X', 'type' => 'bank', 'member_ids' => [$parent->id], $field => $value], ['Accept' => 'application/json']);
            $response->assertStatus(422)->assertJsonValidationErrors([$field]);
            $bodies[$field][$i] = $response->json();
        }
        expect($bodies[$field][0])->toEqual($bodies[$field][1]);
    }

    expect($empty->fresh()->currency)->toBe('EUR');
});

it('shows opening data and the currency lock to a ticked admin with finance access', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $account = FinanceAccount::factory()->withMembers($admin)->create(['opening_balance_minor' => -4200, 'balance_as_of' => '2026-02-01']);

    $this->actingAs($admin)->get('/settings/finance-accounts')->assertInertia(fn (Assert $p) => $p
        ->where('accounts.0.sharedWithMe', true)
        ->where('accounts.0.opening', ['minor' => '-4200', 'asOf' => '2026-02-01', 'currencyLocked' => false]));

    FinanceTransaction::factory()->onAccount($account)->expense(100)->create(['booked_on' => '2026-03-01']);

    $this->actingAs($admin)->get('/settings/finance-accounts')->assertInertia(fn (Assert $p) => $p
        ->where('accounts.0.opening.currencyLocked', true));
});

it('hides opening data from an admin ticked on an account but without finance access', function () {
    $admin = fhAdminUser();
    FinanceAccount::factory()->withMembers($admin)->create(['opening_balance_minor' => 555]);

    $this->actingAs($admin)->get('/settings/finance-accounts')->assertInertia(fn (Assert $p) => $p
        ->where('accounts.0.sharedWithMe', true)
        ->where('accounts.0.opening', null));
});

it('lets a ticked admin change currency only while the account has no transactions', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $account = FinanceAccount::factory()->withMembers($admin)->create();
    $url = "/settings/finance-accounts/{$account->id}";
    $base = ['name' => 'Acc', 'type' => 'savings', 'member_ids' => [$admin->id]];

    $this->actingAs($admin)->patch($url, $base + ['currency' => 'PLN', 'opening_balance' => '10,5', 'balance_as_of' => '2026-02-02'])->assertStatus(303);
    $account->refresh();
    expect($account->currency)->toBe('PLN')->and($account->opening_balance_minor)->toBe(1050)->and($account->type)->toBe('savings');

    FinanceTransaction::factory()->onAccount($account)->expense(100)->create(['booked_on' => '2026-03-01']);

    $this->actingAs($admin)->patch($url, $base + ['currency' => 'EUR'])->assertSessionHasErrors(['currency' => 'finance.acc.currency_locked']);
    // same currency is not a change
    $this->actingAs($admin)->patch($url, $base + ['currency' => 'PLN'])->assertStatus(303);
    // balance date may not move past the earliest transaction
    $this->actingAs($admin)->patch($url, $base + ['balance_as_of' => '2026-03-02'])->assertSessionHasErrors(['balance_as_of' => 'finance.error.date_before_open']);
    $this->actingAs($admin)->patch($url, $base + ['balance_as_of' => '2026-03-01'])->assertStatus(303);
});

it('opens the panel for a known account and renders the forbidden page for an unknown id', function () {
    $admin = fhAdminUser();
    $account = FinanceAccount::factory()->create();

    $this->actingAs($admin)->get('/settings/finance-accounts?acct='.$account->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('panel', $account->id));
    $this->actingAs($admin)->get('/settings/finance-accounts?acct=new')->assertOk()->assertInertia(fn (Assert $p) => $p->where('panel', 'new'));
    $this->actingAs($admin)->get('/settings/finance-accounts?acct='.Str::uuid7())->assertForbidden()->assertInertia(fn (Assert $p) => $p->component('Errors/Forbidden'));
    $this->actingAs($admin)->get('/settings/finance-accounts?acct=junk')->assertForbidden();
});

it('adds the finance accounts section after audit for admins only', function () {
    $keys = fn (User $u) => array_column(SettingsSections::for($u), 'key');
    $admin = fhAdminUser();

    $adminKeys = $keys($admin);
    expect($adminKeys)->toContain('finance_accounts')
        ->and(array_search('finance_accounts', $adminKeys))->toBe(array_search('audit', $adminKeys) + 1)
        ->and(collect(SettingsSections::for($admin))->firstWhere('key', 'finance_accounts'))->toMatchArray(['labelKey' => 'settings.section.finance_accounts', 'icon' => 'wallet'])
        ->and($keys(fhParent()))->not->toContain('finance_accounts');
});

it('keeps non-admins out of the write routes', function () {
    $parent = fhParent();
    $this->actingAs($parent)->post('/settings/finance-accounts', fhStore())->assertForbidden();
    expect(FinanceAccount::query()->count())->toBe(0);
});

it('refuses currency and opening date changes while recurring items or a draft import depend on the account', function () {
    $admin = fhAdminUser(AccessLevel::Edit);
    $account = FinanceAccount::factory()->withMembers($admin)->create(['currency' => 'EUR', 'balance_as_of' => '2026-01-01']);
    $url = "/settings/finance-accounts/{$account->id}";
    $base = ['name' => 'Acc', 'type' => 'bank', 'member_ids' => [$admin->id]];

    // Recurring item, no transactions: locked.
    $rec = FinanceRecurring::factory()->onAccount($account)->create();
    $this->actingAs($admin)->patch($url, $base + ['currency' => 'PLN'])->assertSessionHasErrors(['currency' => 'finance.acc.currency_locked']);
    $this->actingAs($admin)->patch($url, $base + ['balance_as_of' => '2026-02-01'])->assertSessionHasErrors(['balance_as_of' => 'finance.acc.opening_locked']);
    // An unchanged value is not a change.
    $this->actingAs($admin)->patch($url, $base + ['currency' => 'EUR', 'balance_as_of' => '2026-01-01'])->assertStatus(303);
    $this->actingAs($admin)->get('/settings/finance-accounts')->assertInertia(fn (Assert $p) => $p->where('accounts.0.opening.currencyLocked', true));

    // Open draft import only: also locked.
    $rec->delete();
    $batch = FinanceImportBatch::factory()->create(['account_id' => $account->id, 'user_id' => $admin->id, 'status' => 'draft']);
    $this->actingAs($admin)->patch($url, $base + ['currency' => 'PLN'])->assertSessionHasErrors(['currency' => 'finance.acc.currency_locked']);

    // Nothing depends any more: allowed.
    $batch->delete();
    $this->actingAs($admin)->patch($url, $base + ['currency' => 'PLN', 'balance_as_of' => '2026-02-01'])->assertStatus(303);
    expect($account->fresh()->currency)->toBe('PLN');
});
