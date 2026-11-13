<?php

use App\Access\Preset;
use App\Finance\Queries\AccountQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

const FH_WP_A = '01900000-0000-7000-8000-00000000010a';
const FH_WP_B = '01900000-0000-7000-8000-00000000010b';
const FH_WP_X = '01900000-0000-7000-8000-0000000001a1';
const FH_WP_Y = '01900000-0000-7000-8000-0000000001b1';
const FH_WP_T = '01900000-0000-7000-8000-0000000001c1';

/**
 * Viewer V sees only account A, owner O only account B. With $transfer the A leg X (-12345) is the outgoing leg of a
 * transfer to B (hidden from V) and B holds the incoming leg Y; otherwise X is a plain expense and B does not exist.
 */
function fhWpBuild(User $viewer, User $owner, bool $transfer): void
{
    DB::table('finance_transactions')->delete();
    DB::table('finance_account_members')->delete();
    DB::table('finance_accounts')->delete();

    $a = FinanceAccount::factory()->withMembers($viewer)->create(['id' => FH_WP_A, 'name' => 'Household']);
    $category = FinanceCategory::query()->where('default_key', 'housing')->firstOrFail();
    $stamp = '2026-03-10 09:00:00+00';

    FinanceTransaction::factory()->onAccount($a)->expense(12345)->create([
        'id' => FH_WP_X, 'booked_on' => '2026-03-10', 'description' => 'Rent share', 'category_id' => $category->id,
        'note' => 'N', 'created_at' => $stamp, 'updated_at' => $stamp,
        'kind' => $transfer ? 'transfer' : 'expense', 'transfer_id' => $transfer ? FH_WP_T : null,
    ]);

    if ($transfer) {
        $b = FinanceAccount::factory()->withMembers($owner)->create(['id' => FH_WP_B, 'name' => 'ZxCanaryFin Savings']);
        FinanceTransaction::factory()->onAccount($b)->create([
            'id' => FH_WP_Y, 'kind' => 'transfer', 'amount_minor' => 12345, 'booked_on' => '2026-03-10', 'description' => 'Rent share',
            'category_id' => $category->id, 'note' => 'N', 'transfer_id' => FH_WP_T, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
    }
}

/** Runs V's write and the follow-up page; returns everything V can observe, normalised. */
function fhWpAct($test, User $viewer, string $method, array $payload): array
{
    $version = FinancePrivacy::inertiaVersion();
    $out = [];
    app('auth')->forgetGuards();
    app('cache')->store()->flush();
    $test->flushSession();

    FinancePrivacy::assertScopedMutations(function () use ($test, $viewer, $method, $payload, &$out) {
        $response = $test->actingAs($viewer)->call($method, '/finance/transactions/'.FH_WP_X, $payload);
        $out['write'] = FinancePrivacy::normalise($response);
        $out['flash'] = json_encode(session('toast'));
    });

    $follow = '/finance/transactions?month=2026-03';
    $out['inertia'] = FinancePrivacy::normalise($test->actingAs($viewer)->get($follow, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]));
    app('auth')->forgetGuards();
    $test->flushSession();
    $out['html'] = FinancePrivacy::normalise($test->actingAs($viewer)->get($follow));

    return $out;
}

function fhWpPayload(): array
{
    $category = FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();

    return ['amount' => '150,00', 'booked_on' => '2026-03-12', 'description' => 'Rent share v2', 'category_id' => $category->id, 'note' => 'N2'];
}

it('edits only the editor leg of a transfer whose counterpart is hidden, byte-identical to editing a plain entry', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create();
    $owner = User::factory()->withPreset(Preset::Parent)->create();

    fhWpBuild($viewer, $owner, false);
    $plain = fhWpAct($this, $viewer, 'PATCH', fhWpPayload());

    fhWpBuild($viewer, $owner, true);
    $balancesBefore = app(AccountQuery::class)->balances(Viewer::for($owner), '2026-12-31');
    $hiddenLegBefore = (array) DB::table('finance_transactions')->where('id', FH_WP_Y)->first();
    $transfer = fhWpAct($this, $viewer, 'PATCH', fhWpPayload());

    expect($transfer)->toEqual($plain);
    expect($plain['write'])->toStartWith("303\n");
    expect($plain['flash'])->toContain('finance.toast.tx_saved');
    expect($plain['inertia'])->toContain('Rent share v2')->and($transfer['inertia'])->toContain('150');

    // The editor's own leg changed, the hidden leg and its account did not.
    $own = FinanceTransaction::query()->findOrFail(FH_WP_X);
    expect($own->amount_minor)->toBe(-15000)->and($own->kind)->toBe('transfer')->and($own->transfer_id)->toBe(FH_WP_T)
        ->and($own->edited_at)->not->toBeNull();
    expect((array) DB::table('finance_transactions')->where('id', FH_WP_Y)->first())->toEqual($hiddenLegBefore);
    expect(app(AccountQuery::class)->balances(Viewer::for($owner), '2026-12-31'))->toEqual($balancesBefore);
});

it('deletes only the editor leg of a transfer whose counterpart is hidden, byte-identical to deleting a plain entry', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create();
    $owner = User::factory()->withPreset(Preset::Parent)->create();

    fhWpBuild($viewer, $owner, false);
    $plain = fhWpAct($this, $viewer, 'DELETE', []);

    fhWpBuild($viewer, $owner, true);
    $balancesBefore = app(AccountQuery::class)->balances(Viewer::for($owner), '2026-12-31');
    $transfer = fhWpAct($this, $viewer, 'DELETE', []);

    expect($transfer)->toEqual($plain);
    expect($plain['write'])->toStartWith("303\n")->and($plain['flash'])->toContain('finance.toast.tx_deleted');

    expect(FinanceTransaction::query()->whereKey(FH_WP_X)->exists())->toBeFalse();
    $remaining = (array) DB::table('finance_transactions')->where('id', FH_WP_Y)->first();
    expect($remaining['transfer_id'])->toBe(FH_WP_T)->and($remaining['amount_minor'])->toBe(12345);
    expect(app(AccountQuery::class)->balances(Viewer::for($owner), '2026-12-31'))->toEqual($balancesBefore);

    // The member of B now sees the remaining leg as an ordinary income, with no trace of A.
    app('auth')->forgetGuards();
    $this->flushSession();
    $response = $this->actingAs($owner)->get('/finance/transactions?month=2026-03&account='.FH_WP_B, ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()]);
    $items = $response->json('props.transactionItems');
    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('income')->and($items[0]['transfer'])->toBeNull()->and($items[0]['amountMinor'])->toBe('12345');
    expect($response->getContent())->not->toContain('Household')->not->toContain('"transfer":{');
});
