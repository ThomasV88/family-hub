<?php

use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

function fhHomeRecurring(FinanceAccount $account, string $startsOn): void
{
    FinanceRecurring::factory()->onAccount($account)->startingOn($startsOn)->create(['name' => 'Bill', 'expected_amount_minor' => 1000]);
}

it('shows the card with counts for a parent with a visible recurring item', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $a = FinanceAccount::factory()->withMembers($user)->create();
    $hidden = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();
    $tz = config('family.timezone');
    fhHomeRecurring($a, now($tz)->startOfMonth()->toDateString());
    fhHomeRecurring($hidden, now($tz)->startOfMonth()->toDateString());

    $expected = app(App\Finance\HomeSummary::class)->forUser($user);

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->component('Home')
        ->where('financeCard.paymentsCount', $expected['paymentsCount'])
        ->where('financeCard.overdueCount', $expected['overdueCount']));
    expect($expected['paymentsCount'])->toBeGreaterThanOrEqual(0);
});

it('has no financeCard key without visible recurring items', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    FinanceAccount::factory()->withMembers($user)->create();
    $other = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();
    fhHomeRecurring($other, now(config('family.timezone'))->startOfMonth()->toDateString());

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->missing('financeCard'));
});

it('has no financeCard key for a child or an admin without grants', function () {
    $a = FinanceAccount::factory()->create();
    fhHomeRecurring($a, now(config('family.timezone'))->startOfMonth()->toDateString());

    foreach ([User::factory()->withPreset(Preset::Child)->create(), User::factory()->create()] as $user) {
        $a->members()->attach($user->id, ['created_at' => now()]);
        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->missing('financeCard'));
    }
});
