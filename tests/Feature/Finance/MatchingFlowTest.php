<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Recurring\RecurringMatchService;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00', config('family.timezone')));
});

function fhMfSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);
    $item = FinanceRecurring::factory()->onAccount($account)->startingOn('2027-03-05')->create(['name' => 'Rent', 'expected_amount_minor' => 45000]);

    return [$user, $account, $item];
}

function fhMfUrl(FinanceRecurring $item, string $action, string $date = '2027-03-05'): string
{
    return '/finance/recurring/'.$item->id.'/bills/'.$date.'/'.$action;
}

function fhMfBill(User $user, FinanceRecurring $item, string $date = '2027-03-05'): ?array
{
    return app(RecurringQuery::class)->bill(Viewer::for($user), (string) $item->id, $date);
}

it('records a payment in one step and matches it', function () {
    [$user, $account, $item] = fhMfSetup();

    $this->actingAs($user)->post(fhMfUrl($item, 'record'), ['amount' => '450,00', 'booked_on' => '2027-03-05'])
        ->assertStatus(303)->assertSessionHas('toast', ['key' => 'finance.toast.recorded']);

    $tx = FinanceTransaction::query()->firstOrFail();
    expect($tx->account_id)->toBe($account->id)
        ->and($tx->kind)->toBe('expense')
        ->and($tx->amount_minor)->toBe(-45000)
        ->and($tx->description)->toBe('Rent')
        ->and(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->matched_by)->toBe('manual')
        ->and(fhMfBill($user, $item)['status'])->toBe('paid');
});

it('uses the occurrence values when nothing is sent and reads Paid differently for another amount', function () {
    [$user, , $item] = fhMfSetup();
    $this->actingAs($user)->post(fhMfUrl($item, 'record'), [])->assertStatus(303);
    $tx = FinanceTransaction::query()->firstOrFail();
    expect($tx->amount_minor)->toBe(-45000)->and($tx->booked_on->format('Y-m-d'))->toBe('2027-03-05');

    $other = FinanceRecurring::factory()->onAccount(FinanceAccount::query()->findOrFail($item->account_id))
        ->startingOn('2027-03-06')->create(['name' => 'Gas', 'expected_amount_minor' => 45000]);
    $this->actingAs($user)->post(fhMfUrl($other, 'record', '2027-03-06'), ['amount' => '460,00'])->assertStatus(303);

    expect(fhMfBill($user, $other, '2027-03-06')['status'])->toBe('paid_differently')
        ->and(fhMfBill($user, $other, '2027-03-06')['match']['differenceMinor'])->toBe('1000');
});

it('records nothing when the amount is invalid and refuses a second record on a paid occurrence', function () {
    [$user, , $item] = fhMfSetup();
    $this->actingAs($user)->postJson(fhMfUrl($item, 'record'), ['amount' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('amount');
    expect(FinanceTransaction::query()->count())->toBe(0);

    $this->actingAs($user)->post(fhMfUrl($item, 'record'), [])->assertStatus(303);
    $this->actingAs($user)->postJson(fhMfUrl($item, 'record'), [])->assertStatus(422);
    expect(FinanceTransaction::query()->count())->toBe(1);
});

it('matches a visible same-account transaction by hand and replaces the current match', function () {
    [$user, $account, $item] = fhMfSetup();
    $first = FinanceTransaction::factory()->onAccount($account)->expense(48000)->create(['booked_on' => '2027-03-07', 'description' => 'First']);
    $second = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-04', 'description' => 'Second']);

    $this->actingAs($user)->post(fhMfUrl($item, 'match'), ['transaction_id' => $first->id])
        ->assertStatus(303)->assertSessionHas('toast', ['key' => 'finance.toast.matched']);
    expect(fhMfBill($user, $item)['status'])->toBe('paid_differently');

    $this->actingAs($user)->post(fhMfUrl($item, 'match'), ['transaction_id' => $second->id])->assertStatus(303);
    expect(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->firstOrFail()->transaction_id)->toBe($second->id)
        ->and(fhMfBill($user, $item)['status'])->toBe('paid');
});

it('answers every unusable transaction id with the same 422 body', function () {
    [$user, $account, $item] = fhMfSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hiddenAccount = FinanceAccount::factory()->withMembers($other)->create(['name' => 'ZxCanaryFin']);
    $visibleOther = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Elsewhere']);
    $hidden = FinanceTransaction::factory()->onAccount($hiddenAccount)->expense(45000)->create(['booked_on' => '2027-03-05']);
    $otherAccount = FinanceTransaction::factory()->onAccount($visibleOther)->expense(45000)->create(['booked_on' => '2027-03-05']);
    $income = FinanceTransaction::factory()->onAccount($account)->income(45000)->create(['booked_on' => '2027-03-05']);
    $taken = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-05']);
    $later = FinanceRecurring::factory()->onAccount($account)->startingOn('2027-03-08')->create(['name' => 'Later']);
    FinanceRecurringMatch::factory()->between($later, '2027-03-08', $taken)->create();

    $bodies = [];
    foreach ([$hidden->id, (string) Str::uuid7(), 'zzz', $otherAccount->id, $income->id, $taken->id] as $id) {
        $response = $this->actingAs($user)->postJson(fhMfUrl($item, 'match'), ['transaction_id' => $id]);
        $response->assertStatus(422);
        $bodies[] = $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and($bodies[0])->toContain('finance.error.transaction')->not->toContain('ZxCanaryFin')
        ->and(FinanceRecurringMatch::query()->count())->toBe(1);
});

it('unmatches, stores a rejection and returns the occurrence to its date-based status', function () {
    [$user, $account, $item] = fhMfSetup();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Rent']);
    FinanceRecurringMatch::factory()->between($item, '2027-03-05', $tx)->create();
    expect(fhMfBill($user, $item)['status'])->toBe('paid');

    $this->actingAs($user)->delete(fhMfUrl($item, 'match'))
        ->assertStatus(303)->assertSessionHas('toast', ['key' => 'finance.toast.unmatched']);

    expect(FinanceRecurringMatch::query()->count())->toBe(0)
        ->and(DB::table('finance_match_rejections')->where('transaction_id', $tx->id)->count())->toBe(1)
        ->and(fhMfBill($user, $item)['status'])->toBe('overdue');
});

it('answers a non-occurrence date, a hidden item and an unknown id with one identical 404 on all three routes', function () {
    [$user, , $item] = fhMfSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hiddenItem = FinanceRecurring::factory()->onAccount(FinanceAccount::factory()->withMembers($other)->create())
        ->startingOn('2027-03-05')->create(['name' => 'ZxCanaryFin']);
    $tx = FinanceTransaction::factory()->onAccount(FinanceAccount::query()->findOrFail($item->account_id))->expense(1000)->create(['booked_on' => '2027-03-05']);

    $targets = [
        ['/finance/recurring/'.$item->id.'/bills/2027-03-06', 'non-occurrence'],
        ['/finance/recurring/'.$hiddenItem->id.'/bills/2027-03-05', 'hidden'],
        ['/finance/recurring/'.Str::uuid7().'/bills/2027-03-05', 'unknown'],
    ];
    foreach ([['post', 'record'], ['post', 'match'], ['delete', 'match']] as [$verb, $action]) {
        $bodies = [];
        foreach ($targets as [$base]) {
            $response = $this->actingAs($user)->{$verb.'Json'}($base.'/'.$action, ['transaction_id' => $tx->id]);
            $response->assertNotFound();
            $bodies[] = $response->getContent();
        }
        expect(array_unique($bodies))->toHaveCount(1);
    }
});

it('forbids view-only users on the three routes', function () {
    [, , $item] = fhMfSetup();
    $viewer = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    $this->actingAs($viewer)->post(fhMfUrl($item, 'record'))->assertForbidden();
    $this->actingAs($viewer)->post(fhMfUrl($item, 'match'))->assertForbidden();
    $this->actingAs($viewer)->delete(fhMfUrl($item, 'match'))->assertForbidden();
});

it('lists ranked same-account candidates only when match=1 and never other accounts', function () {
    [$user, $account, $item] = fhMfSetup();
    $near = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-06', 'description' => 'Near']);
    $far = FinanceTransaction::factory()->onAccount($account)->expense(1000)->create(['booked_on' => '2027-04-20', 'description' => 'Far']);
    $tooFar = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-06-20', 'description' => 'Too far']);
    $income = FinanceTransaction::factory()->onAccount($account)->income(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Income']);
    $elsewhere = FinanceTransaction::factory()->onAccount(FinanceAccount::factory()->withMembers($user)->create())->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Elsewhere']);
    $hidden = FinanceTransaction::factory()->onAccount(FinanceAccount::factory()->create())->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'ZxCanaryFin']);
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()];

    $plain = $this->actingAs($user)->get('/finance/recurring?bill='.$item->id.'.2027-03-05', $headers)->json('props.candidates');
    $props = $this->actingAs($user)->get('/finance/recurring?bill='.$item->id.'.2027-03-05&match=1', $headers)->json('props');

    expect($plain)->toBeNull()
        ->and(array_column($props['candidates'], 'id'))->toBe([$near->id, $far->id])
        ->and($props['candidates'][0])->toBe(['id' => $near->id, 'description' => 'Near', 'bookedOn' => '2027-03-06', 'accountName' => 'Main', 'amountMinor' => '-45000'])
        ->and(json_encode($props))->not->toContain('ZxCanaryFin');
    expect([$tooFar->id, $income->id, $elsewhere->id, $hidden->id])->each->not->toBeIn(array_column($props['candidates'], 'id'));
});

it('shows what a transaction pays on rows and in the panel', function () {
    [$user, $account, $item] = fhMfSetup();
    $paid = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Landlord']);
    $free = FinanceTransaction::factory()->onAccount($account)->expense(1000)->create(['booked_on' => '2027-03-06', 'description' => 'Coffee']);
    FinanceRecurringMatch::factory()->between($item, '2027-03-05', $paid)->create();
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()];

    $props = $this->actingAs($user)->get('/finance/transactions?month=2027-03&tx='.$paid->id, $headers)->json('props');
    $rows = collect($props['transactionItems'])->keyBy('id');
    $plainPanel = $this->actingAs($user)->get('/finance/transactions?month=2027-03&tx='.$free->id, $headers)->json('props.panelTransaction.match');

    expect($rows[$paid->id]['matchedRecurringName'])->toBe('Rent')
        ->and($rows[$free->id]['matchedRecurringName'])->toBeNull()
        ->and($props['panelTransaction']['match'])->toBe(['recurringId' => $item->id, 'recurringName' => 'Rent', 'dueDate' => '2027-03-05'])
        ->and($plainPanel)->toBeNull();
});

it('books one payment when the same occurrence is recorded twice (double submit past the request check)', function () {
    [$user, , $item] = fhMfSetup();
    $viewer = Viewer::for($user);
    $service = app(RecurringMatchService::class);
    $payment = ['minor' => 45000, 'booked_on' => '2027-03-05', 'description' => 'Rent', 'category_id' => null, 'note' => null];
    $locked = false;
    DB::listen(function ($q) use (&$locked) {
        $locked = $locked || (str_contains($q->sql, 'finance_recurring"') && str_contains($q->sql, 'for update'));
    });

    $service->recordPayment($viewer, $item, '2027-03-05', $payment);
    expect(fn () => $service->recordPayment($viewer, $item, '2027-03-05', $payment))
        ->toThrow(ValidationException::class);

    expect($locked)->toBeTrue()
        ->and(FinanceTransaction::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->value('transaction_id'))->toBe(FinanceTransaction::query()->value('id'));
});
