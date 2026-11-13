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
use Tests\Support\FinancePrivacy;

function fhTxInertia($test, User $user, string $url, array $extra = []): Illuminate\Testing\TestResponse
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
