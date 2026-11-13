<?php

use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only visible accounts with their balance as of today', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $mine = FinanceAccount::factory()->withMembers($parent)->create(['name' => 'Mine', 'opening_balance_minor' => 10000, 'balance_as_of' => '2020-01-01']);
    $hidden = FinanceAccount::factory()->withMembers($other)->create(['name' => 'ZxCanaryFin Hidden', 'opening_balance_minor' => 777777]);
    FinanceTransaction::factory()->onAccount($mine)->expense(2500)->create(['booked_on' => '2021-01-01']);
    FinanceTransaction::factory()->onAccount($mine)->expense(900)->create(['booked_on' => '2999-01-01']);
    FinanceTransaction::factory()->onAccount($hidden)->expense(1)->create(['booked_on' => '2021-01-01']);

    $response = $this->actingAs($parent)->get('/finance/accounts');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Finance/Accounts', false)
        ->has('accounts', 1)
        ->where('accounts.0.id', $mine->id)
        ->where('accounts.0.balanceMinor', '7500')
        ->where('finance.accounts.0.id', $mine->id)
        ->where('finance.selectedAccount', 'all')
        ->where('finance.canEdit', true)
        ->where('finance.isAdmin', false)
        ->has('finance.categories')
        ->where('finance.currencies', ['EUR'])
    );
    expect($response->getContent())->not->toContain('ZxCanaryFin')->not->toContain($hidden->id);
});

it('forbids members without finance access', function () {
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($child)->get('/finance/accounts')->assertForbidden();
});
