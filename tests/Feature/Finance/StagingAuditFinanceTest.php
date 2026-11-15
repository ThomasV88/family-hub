<?php

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

it('passes with finance rows created only by synthetic users', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $account = FinanceAccount::factory()->withMembers($synthetic)->create(['created_by' => $synthetic->id]);
    FinanceTransaction::factory()->onAccount($account)->create(['created_by' => $synthetic->id]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails on a finance account, transaction and membership of a real user', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    $account = FinanceAccount::factory()->withMembers($real)->create(['created_by' => $real->id]);
    FinanceTransaction::factory()->onAccount($account)->create(['created_by' => $real->id]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic finance_accounts')
        ->expectsOutputToContain('non-synthetic finance_transactions')
        ->expectsOutputToContain('non-synthetic finance_account_members')
        ->assertExitCode(1);

    expect($synthetic->exists)->toBeTrue();
});

it('fails on finance rows whose creator was deleted (null created_by)', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    FinanceAccount::factory()->create(['created_by' => null]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic finance_accounts')->assertExitCode(1);
});
