<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FinancePrivacy;

function fhTxInertia($test, User $user, string $url, array $extra = []): TestResponse
{
    return $test->actingAs($user)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()] + $extra);
}

function fhTxSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);

    return [$user, $account];
}

it('lists a month newest first, with integer string amounts and a total', function () {
    [$user, $account] = fhTxSetup();
    FinanceTransaction::factory()->onAccount($account)->expense(1250)->create(['booked_on' => '2026-03-05', 'description' => 'Old']);
    FinanceTransaction::factory()->onAccount($account)->income(99999)->create(['booked_on' => '2026-03-20', 'description' => 'New']);
    FinanceTransaction::factory()->onAccount($account)->expense(100)->create(['booked_on' => '2026-04-01', 'description' => 'April']);

    $json = fhTxInertia($this, $user, '/finance/transactions?month=2026-03')->assertOk()->json('props');

    expect(array_column($json['transactionItems'], 'description'))->toBe(['New', 'Old'])
        ->and($json['transactionItems'][0]['amountMinor'])->toBe('99999')
        ->and($json['transactionItems'][1]['amountMinor'])->toBe('-1250')
        ->and($json['transactions'])->toBe(['total' => 2, 'page' => 1, 'hasMore' => false])
        ->and($json['finance']['month'])->toBe('2026-03');
});

it('falls back to the current month for a malformed month', function () {
    [$user] = fhTxSetup();

    $month = fhTxInertia($this, $user, '/finance/transactions?month=2026-13')->json('props.finance.month');

    expect($month)->toBe(now(config('family.timezone'))->format('Y-m'));
});

it('pages 50 rows per request: partial reloads return page N only, full visits always page 1', function () {
    [$user, $account] = fhTxSetup();
    for ($i = 1; $i <= 120; $i++) {
        FinanceTransaction::factory()->onAccount($account)->expense($i)->create([
            'booked_on' => '2026-03-15', 'description' => 'Row '.$i, 'created_at' => now()->addSeconds($i),
        ]);
    }
    $partial = ['X-Inertia-Partial-Component' => 'Finance/Transactions', 'X-Inertia-Partial-Data' => 'transactionItems,transactions'];

    $first = fhTxInertia($this, $user, '/finance/transactions?month=2026-03')->json('props');
    $second = fhTxInertia($this, $user, '/finance/transactions?month=2026-03&page=2', $partial)->json('props');
    $third = fhTxInertia($this, $user, '/finance/transactions?month=2026-03&page=3', $partial)->json('props');
    $beyond = fhTxInertia($this, $user, '/finance/transactions?month=2026-03&page=99', $partial)->json('props');
    $full = fhTxInertia($this, $user, '/finance/transactions?month=2026-03&page=5')->json('props');

    expect($first['transactionItems'])->toHaveCount(50)
        ->and($first['transactions'])->toBe(['total' => 120, 'page' => 1, 'hasMore' => true])
        ->and($first['transactionItems'][0]['description'])->toBe('Row 120')
        ->and($second['transactionItems'])->toHaveCount(50)
        ->and($second['transactionItems'][0]['description'])->toBe('Row 70')
        ->and($second['transactions']['page'])->toBe(2)
        ->and($third['transactionItems'])->toHaveCount(20)
        ->and($third['transactions'])->toBe(['total' => 120, 'page' => 3, 'hasMore' => false])
        ->and($beyond['transactions']['page'])->toBe(3)
        ->and($full['transactions']['page'])->toBe(1)
        ->and($full['transactionItems'])->toHaveCount(50);

    $page = fhTxInertia($this, $user, '/finance/transactions?month=2026-03', $partial)->json();
    expect($page['mergeProps'])->toContain('transactionItems');
});

it('filters by search, type, category, source and account', function () {
    [$user, $account] = fhTxSetup();
    $second = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Second']);
    $cat = FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();
    FinanceTransaction::factory()->onAccount($account)->expense(100)->create(['booked_on' => '2026-03-02', 'description' => '100% Milk', 'category_id' => $cat->id]);
    FinanceTransaction::factory()->onAccount($account)->expense(200)->create(['booked_on' => '2026-03-03', 'description' => '1000 Bread']);
    FinanceTransaction::factory()->onAccount($account)->income(300)->create(['booked_on' => '2026-03-04', 'description' => 'a_b', 'source' => 'import']);
    FinanceTransaction::factory()->onAccount($second)->income(400)->create(['booked_on' => '2026-03-05', 'description' => 'Other account']);

    $ids = fn (string $q) => array_column(fhTxInertia($this, $user, '/finance/transactions?month=2026-03'.$q)->json('props.transactionItems'), 'description');

    expect($ids('&q='.urlencode('100%')))->toBe(['100% Milk'])
        ->and($ids('&q='.urlencode('a_b')))->toBe(['a_b'])
        ->and($ids('&q=MILK'))->toBe(['100% Milk'])
        ->and($ids('&q='.urlencode('a_')))->toBe(['a_b'])
        ->and($ids('&type=income'))->toBe(['Other account', 'a_b'])
        ->and($ids('&type=expense'))->toBe(['1000 Bread', '100% Milk'])
        ->and($ids('&category=none'))->toBe(['Other account', 'a_b', '1000 Bread'])
        ->and($ids('&category='.$cat->id))->toBe(['100% Milk'])
        ->and($ids('&source=import'))->toBe(['a_b'])
        ->and($ids('&account='.$second->id))->toBe(['Other account'])
        ->and($ids('&type=transfer'))->toBe([]);
});

it('answers hidden, unknown and malformed account and panel ids with one identical 403', function () {
    [$user, $account] = fhTxSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $hiddenTx = FinanceTransaction::factory()->onAccount($hidden)->expense(5)->create(['booked_on' => '2026-03-02']);

    $bodies = [];
    foreach (['account='.$hidden->id, 'account='.Str::uuid7(), 'account=zzz', 'tx='.$hiddenTx->id, 'tx='.Str::uuid7(), 'tx=zzz'] as $q) {
        $response = $this->actingAs($user)->get('/finance/transactions?month=2026-03&'.$q);
        $response->assertForbidden();
        // Only the echoed request URL may differ.
        $body = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1', $response->getContent());
        $bodies[] = preg_replace('/&quot;url&quot;:&quot;.*?&quot;/', '', preg_replace('/"url":"[^"]*"/', '', $body));
    }
    expect(array_unique($bodies))->toHaveCount(1);
});

it('opens the new-transaction panel only for editors', function () {
    [$user] = fhTxSetup();
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    expect(fhTxInertia($this, $user, '/finance/transactions?tx=new')->json('props.panelTransaction'))->toBe(['mode' => 'new']);
    fhTxInertia($this, $viewOnly, '/finance/transactions?tx=new')->assertForbidden();
    fhTxInertia($this, $viewOnly, '/finance/transactions')->assertOk();
});

it('carries the membership subquery in every statement of the list and panel requests', function () {
    [$user, $account] = fhTxSetup();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(5)->create(['booked_on' => '2026-03-02']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    fhTxInertia($this, $user, '/finance/transactions?month=2026-03&tx='.$tx->id.'&q=a&type=income&category=none');
    fhTxInertia($this, $user, '/finance/transactions?account='.$account->id);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    $touching = array_filter($statements, fn ($s) => str_contains($s, 'finance_transactions') || str_contains($s, 'finance_accounts'));
    expect($touching)->not->toBeEmpty()->and(FinancePrivacy::unscopedReads($statements))->toBe([]);
});

// ---- writes ----

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fhTxPost($test, User $user, array $data)
{
    return $test->actingAs($user)->postJson('/finance/transactions', $data);
}

it('stores an expense and an income in the account currency with exact minor units', function () {
    [$user, $account] = fhTxSetup();
    $expenseCat = FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();
    $incomeCat = FinanceCategory::query()->where('default_key', 'salary')->firstOrFail();

    $this->actingAs($user)->post('/finance/transactions', [
        'kind' => 'expense', 'amount' => '12,34', 'booked_on' => '2026-03-05', 'description' => 'Milk',
        'category_id' => $expenseCat->id, 'account_id' => $account->id, 'note' => 'fresh',
    ])->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.tx_saved');
    $this->actingAs($user)->post('/finance/transactions', [
        'kind' => 'income', 'amount' => '1000', 'booked_on' => '2026-03-06', 'description' => 'Pay',
        'category_id' => $incomeCat->id, 'account_id' => $account->id,
    ])->assertStatus(303);

    $rows = FinanceTransaction::query()->orderBy('booked_on')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->amount_minor)->toBe(-1234)
        ->and($rows[0]->kind)->toBe('expense')
        ->and($rows[0]->currency)->toBe('EUR')
        ->and($rows[0]->source)->toBe('manual')
        ->and($rows[0]->created_by)->toBe($user->id)
        ->and($rows[0]->edited_at)->toBeNull()
        ->and($rows[0]->note)->toBe('fresh')
        ->and($rows[1]->amount_minor)->toBe(100000);
});

it('creates two linked legs for a transfer and rejects bad transfers', function () {
    [$user, $a] = fhTxSetup();
    $b = FinanceAccount::factory()->withMembers($user)->create();
    $usd = FinanceAccount::factory()->withMembers($user)->create(['currency' => 'USD']);

    fhTxPost($this, $user, ['kind' => 'transfer', 'from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => '25', 'booked_on' => '2026-03-05', 'description' => 'Move'])
        ->assertRedirect();
    $legs = FinanceTransaction::query()->orderBy('amount_minor')->get();
    expect($legs)->toHaveCount(2)
        ->and($legs[0]->amount_minor)->toBe(-2500)->and($legs[0]->account_id)->toBe($a->id)
        ->and($legs[1]->amount_minor)->toBe(2500)->and($legs[1]->account_id)->toBe($b->id)
        ->and($legs[0]->transfer_id)->not->toBeNull()->and($legs[0]->transfer_id)->toBe($legs[1]->transfer_id);

    fhTxPost($this, $user, ['kind' => 'transfer', 'from_account_id' => $a->id, 'to_account_id' => $a->id, 'amount' => '5', 'booked_on' => '2026-03-05'])
        ->assertJsonValidationErrors(['to_account_id' => 'finance.error.transfer_same']);
    fhTxPost($this, $user, ['kind' => 'transfer', 'from_account_id' => $a->id, 'to_account_id' => $usd->id, 'amount' => '5', 'booked_on' => '2026-03-05'])
        ->assertJsonValidationErrors(['to_account_id' => 'finance.error.transfer_currency']);
    expect(FinanceTransaction::query()->count())->toBe(2);
});

it('answers a hidden account like a nonexistent one and validates the other fields', function () {
    [$user, $account] = fhTxSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $base = ['kind' => 'expense', 'amount' => '5', 'booked_on' => '2026-03-05', 'description' => 'x'];

    $hiddenBody = fhTxPost($this, $user, $base + ['account_id' => $hidden->id]);
    $randomBody = fhTxPost($this, $user, $base + ['account_id' => (string) Str::uuid7()]);
    $hiddenBody->assertStatus(422)->assertJsonValidationErrors(['account_id' => 'finance.error.account']);
    expect($hiddenBody->getContent())->toBe($randomBody->getContent());

    $incomeCat = FinanceCategory::query()->where('default_key', 'salary')->firstOrFail();
    $post = fn (array $override) => fhTxPost($this, $user, array_merge($base, ['account_id' => $account->id], $override));

    $post(['category_id' => $incomeCat->id])->assertJsonValidationErrors(['category_id' => 'finance.error.category']);
    $post(['category_id' => (string) Str::uuid7()])->assertJsonValidationErrors(['category_id' => 'finance.error.category']);
    foreach (['0,001', '0', 'abc', '-5', '1,2,3'] as $amount) {
        $post(['amount' => $amount])->assertJsonValidationErrors(['amount' => 'finance.error.amount']);
    }
    $post(['booked_on' => '2019-12-31'])->assertJsonValidationErrors(['booked_on' => 'finance.error.date_before_open']);
    $post(['booked_on' => '2026-02-30'])->assertJsonValidationErrors(['booked_on' => 'finance.error.date']);
    $post(['description' => ''])->assertJsonValidationErrors(['description' => 'finance.error.description']);
    expect(FinanceTransaction::query()->count())->toBe(0);
});

it('forbids writes for view-only users and gives 404 for transactions of accounts the editor cannot see', function () {
    [$user, $account] = fhTxSetup();
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hiddenAccount = FinanceAccount::factory()->withMembers($other)->create();
    $hiddenTx = FinanceTransaction::factory()->onAccount($hiddenAccount)->expense(500)->create(['booked_on' => '2026-03-05']);
    $own = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['booked_on' => '2026-03-05']);

    fhTxPost($this, $viewOnly, ['kind' => 'expense'])->assertForbidden();
    $this->actingAs($viewOnly)->patchJson('/finance/transactions/'.$own->id, ['amount' => '1'])->assertForbidden();
    $this->actingAs($viewOnly)->deleteJson('/finance/transactions/'.$own->id)->assertForbidden();

    $this->actingAs($user)->patchJson('/finance/transactions/'.$hiddenTx->id, ['amount' => '1'])->assertNotFound();
    $this->actingAs($user)->deleteJson('/finance/transactions/'.$hiddenTx->id)->assertNotFound();
    $this->actingAs($user)->patchJson('/finance/transactions/'.Str::uuid7(), ['amount' => '1'])->assertNotFound();
    expect($hiddenTx->fresh()->amount_minor)->toBe(-500)->and(FinanceTransaction::query()->count())->toBe(2);
});

it('edits a transaction and stamps edited_at only on changed legs', function () {
    [$user, $account] = fhTxSetup();
    $cat = FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['booked_on' => '2026-03-05', 'description' => 'Old']);

    $this->actingAs($user)->patchJson('/finance/transactions/'.$tx->id, ['amount' => '1,00', 'booked_on' => '2026-03-09', 'description' => 'New', 'category_id' => $cat->id, 'note' => 'n'])
        ->assertRedirect();
    $tx->refresh();
    expect($tx->amount_minor)->toBe(-100)->and($tx->booked_on->format('Y-m-d'))->toBe('2026-03-09')
        ->and($tx->description)->toBe('New')->and($tx->category_id)->toBe($cat->id)->and($tx->note)->toBe('n')
        ->and($tx->edited_at)->not->toBeNull();

    $untouched = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['booked_on' => '2026-03-05', 'description' => 'Same']);
    $this->actingAs($user)->patchJson('/finance/transactions/'.$untouched->id, ['amount' => '5,00', 'description' => 'Same'])->assertRedirect();
    expect($untouched->fresh()->edited_at)->toBeNull();
});

it('propagates amount, date and description across a fully visible transfer, keeping note and category per leg', function () {
    [$user, $a] = fhTxSetup();
    $b = FinanceAccount::factory()->withMembers($user)->create();
    fhTxPost($this, $user, ['kind' => 'transfer', 'from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => '25', 'booked_on' => '2026-03-05', 'description' => 'Move', 'note' => 'from note']);
    $out = FinanceTransaction::query()->where('amount_minor', '<', 0)->firstOrFail();

    $this->actingAs($user)->patchJson('/finance/transactions/'.$out->id, ['amount' => '40,50', 'booked_on' => '2026-03-12', 'description' => 'Moved', 'note' => 'changed'])->assertRedirect();

    $legs = FinanceTransaction::query()->orderBy('amount_minor')->get();
    expect($legs[0]->amount_minor)->toBe(-4050)->and($legs[1]->amount_minor)->toBe(4050)
        ->and($legs[0]->booked_on->format('Y-m-d'))->toBe('2026-03-12')->and($legs[1]->booked_on->format('Y-m-d'))->toBe('2026-03-12')
        ->and($legs[1]->description)->toBe('Moved')
        ->and($legs[0]->note)->toBe('changed')->and($legs[1]->note)->toBeNull()
        ->and($legs[0]->edited_at)->not->toBeNull()->and($legs[1]->edited_at)->not->toBeNull();

    // Deleting either leg of a fully visible transfer deletes both.
    $this->actingAs($user)->delete('/finance/transactions/'.$legs[1]->id)->assertStatus(303)->assertSessionHas('toast.key', 'finance.toast.tx_deleted');
    expect(FinanceTransaction::query()->count())->toBe(0);
});

it('rejects account and kind changes on PATCH with one message, whatever the value', function () {
    [$user, $account] = fhTxSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['booked_on' => '2026-03-05']);

    foreach (['account_id', 'kind', 'from_account_id', 'to_account_id'] as $key) {
        $bodies = [];
        foreach ([$account->id, $hidden->id, (string) Str::uuid7(), 'income'] as $value) {
            $response = $this->actingAs($user)->patchJson('/finance/transactions/'.$tx->id, [$key => $value, 'amount' => '9']);
            $response->assertStatus(422)->assertJsonValidationErrors([$key => 'finance.error.immutable']);
            $bodies[] = $response->getContent();
        }
        expect(array_unique($bodies))->toHaveCount(1);
    }
    $fresh = $tx->fresh();
    expect($fresh->account_id)->toBe($account->id)->and($fresh->amount_minor)->toBe(-500)->and($fresh->kind)->toBe('expense');
});

it('deletes a transaction', function () {
    [$user, $account] = fhTxSetup();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(500)->create(['booked_on' => '2026-03-05']);

    $this->actingAs($user)->delete('/finance/transactions/'.$tx->id, [], ['Referer' => 'http://localhost/finance/transactions?month=2026-03&account='.$account->id.'&tx='.$tx->id])
        ->assertRedirect(route('finance.transactions.index', ['account' => $account->id, 'month' => '2026-03']));
    expect(FinanceTransaction::query()->count())->toBe(0);
});
