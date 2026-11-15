<?php

use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinancePrivacy;

const FH_TP_A = '01900000-0000-7000-8000-00000000000a';
const FH_TP_X = '01900000-0000-7000-8000-0000000000a1';
const FH_TP_Y = '01900000-0000-7000-8000-0000000000b1';
const FH_TP_B = '01900000-0000-7000-8000-00000000000b';

/**
 * Builds a family around viewer V: account A (V and O) with a leg X of -12345. With $transfer the leg X is the outgoing leg
 * of a transfer to account B, which V cannot see; otherwise X is a plain expense and B does not exist.
 */
function fhTpBuild(User $viewer, User $owner, bool $transfer): void
{
    DB::table('finance_transactions')->delete();
    DB::table('finance_account_members')->delete();
    DB::table('finance_accounts')->delete();

    $a = FinanceAccount::factory()->withMembers($viewer, $owner)->create(['id' => FH_TP_A, 'name' => 'Household']);
    $category = FinanceCategory::query()->where('default_key', 'housing')->firstOrFail();
    $stamp = '2026-03-10 09:00:00+00';

    FinanceTransaction::factory()->onAccount($a)->expense(12345)->create([
        'id' => FH_TP_X, 'booked_on' => '2026-03-10', 'description' => 'Rent share',
        'category_id' => $category->id, 'note' => 'n', 'created_at' => $stamp, 'updated_at' => $stamp,
        'kind' => $transfer ? 'transfer' : 'expense',
        'transfer_id' => $transfer ? '01900000-0000-7000-8000-0000000000c1' : null,
    ]);

    if ($transfer) {
        $b = FinanceAccount::factory()->withMembers($owner)->create(['id' => FH_TP_B, 'name' => 'ZxCanaryFin Savings']);
        FinanceTransaction::factory()->onAccount($b)->create([
            'id' => FH_TP_Y, 'kind' => 'transfer', 'amount_minor' => 12345, 'booked_on' => '2026-03-10',
            'description' => 'Rent share', 'transfer_id' => '01900000-0000-7000-8000-0000000000c1',
            'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
    }
}

function fhTpCapture($test, User $viewer, string $url): array
{
    $out = [];
    foreach (['inertia', 'html'] as $kind) {
        app('auth')->forgetGuards();
        app('cache')->store()->flush();
        $test->flushSession();
        $headers = $kind === 'inertia' ? ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()] : [];
        $out[$kind] = FinancePrivacy::normalise($test->actingAs($viewer)->get($url, $headers));
    }

    return $out;
}

it('delivers a transfer to a hidden account byte-identical to a plain entry', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create();
    $owner = User::factory()->withPreset(Preset::Parent)->create();

    foreach (['/finance/transactions?month=2026-03', '/finance/transactions?month=2026-03&tx='.FH_TP_X, '/finance/transactions?month=2026-03&type=income', '/finance/transactions?month=2026-03&type=expense&account='.FH_TP_A] as $url) {
        fhTpBuild($viewer, $owner, false);
        $plain = fhTpCapture($this, $viewer, $url);
        fhTpBuild($viewer, $owner, true);
        $hidden = fhTpCapture($this, $viewer, $url);

        expect($hidden)->toEqual($plain);
        expect($hidden['inertia'])->toStartWith("200\n\n{");
        foreach ($hidden as $body) {
            expect($body)->not->toContain(FH_TP_B)->not->toContain('ZxCanaryFin')->not->toContain('"transfer":{');
        }
    }

    // The plain rendering really lists the entry (guards against a vacuous comparison).
    fhTpBuild($viewer, $owner, true);
    $json = $this->actingAs($viewer)->get('/finance/transactions?month=2026-03', ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()])->json();
    expect($json['props']['transactionItems'][0])->toMatchArray(['id' => FH_TP_X, 'kind' => 'expense', 'amountMinor' => '-12345', 'transfer' => null]);
});

it('shows a transfer between two visible accounts once under all accounts', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create();
    $a = FinanceAccount::factory()->withMembers($viewer)->create(['name' => 'Alpha']);
    $b = FinanceAccount::factory()->withMembers($viewer)->create(['name' => 'Beta']);
    $id = (string) Str::uuid7();
    FinanceTransaction::factory()->onAccount($a)->create(['kind' => 'transfer', 'amount_minor' => -5000, 'transfer_id' => $id, 'booked_on' => '2026-03-04', 'description' => 'Move']);
    FinanceTransaction::factory()->onAccount($b)->create(['kind' => 'transfer', 'amount_minor' => 5000, 'transfer_id' => $id, 'booked_on' => '2026-03-04', 'description' => 'Move']);

    $items = fn (string $q) => $this->actingAs($viewer)->get('/finance/transactions?month=2026-03'.$q, ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()])->json('props.transactionItems');

    $all = $items('');
    expect($all)->toHaveCount(1)
        ->and($all[0]['kind'])->toBe('transfer')
        ->and($all[0]['amountMinor'])->toBe('5000')
        ->and($all[0]['transfer'])->toMatchArray(['fromId' => $a->id, 'fromName' => 'Alpha', 'toId' => $b->id, 'toName' => 'Beta']);
    expect($items('&account='.$b->id))->toHaveCount(1)
        ->and($items('&type=transfer'))->toHaveCount(1)
        ->and($items('&type=income'))->toHaveCount(0);
});
