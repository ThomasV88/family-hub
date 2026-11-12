<?php

use App\Models\User;

beforeEach(function () {
    $this->originalEnv = app()->environment();
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function setEnv(string $env): void
{
    app()['env'] = $env;
}

it('refuses to seed in production', function () {
    setEnv('production');

    $this->artisan('staging:seed --force')
        ->expectsOutputToContain('refused')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('requires --force to seed', function () {
    setEnv('staging');

    $this->artisan('staging:seed')->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('seeds exactly six synthetic users on staging', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(6)
        ->and(User::where('email', 'not like', '%@example.test')->count())->toBe(0);
});

it('is idempotent', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);
    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(6);
});

it('audit passes when all users are synthetic', function () {
    setEnv('staging');
    User::factory()->create(['email' => 'a.1@example.test']);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('audit fails and names the count when real data exists', function () {
    setEnv('staging');
    User::factory()->create(['email' => 'a.1@example.test']);
    User::factory()->create(['email' => 'real@gmail.com']);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('1 non-synthetic')
        ->assertExitCode(1);
});

it('audit refuses in production', function () {
    setEnv('production');

    $this->artisan('staging:audit')->assertExitCode(1);
});
