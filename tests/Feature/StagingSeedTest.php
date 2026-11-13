<?php

use App\Models\ModulePermission;
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

it('seeds six synthetic members and one synthetic admin on staging', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(7)
        ->and(User::where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(User::where('is_admin', true)->count())->toBe(1)
        ->and(User::where('is_admin', true)->first()->email)->toBe('admin@example.test');
});

it('gives members preset grants and the admin none', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    $admin = User::where('is_admin', true)->first();
    expect($admin->modulePermissions()->count())->toBe(0);
    foreach (User::where('is_admin', false)->get() as $member) {
        expect($member->modulePermissions()->count())->toBe(4);
    }
    expect(ModulePermission::where('module', 'health')->where('level', '>', 0)->count())->toBe(0);
});

it('is idempotent', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);
    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(7);
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

it('audit fails on a real invited email, and on audit rows of real users', function () {
    setEnv('staging');
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    DB::table('invitations')->insert([
        'email' => 'real.person@gmail.com', 'token_hash' => str_repeat('a', 64), 'preset' => 'child', 'locale' => 'sk',
        'invited_by' => $synthetic->id, 'expires_at' => now()->addDay(),
    ]);
    DB::table('audit_logs')->insert(['event' => 'invite_created', 'actor_id' => $synthetic->id, 'meta' => json_encode(['email' => 'real.person@gmail.com'])]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('1 non-synthetic invitations')
        ->expectsOutputToContain('1 non-synthetic audit_logs')
        ->assertExitCode(1);
});

it('audit passes when invitations and audit rows only reference synthetic data', function () {
    setEnv('staging');
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    DB::table('invitations')->insert([
        'email' => 'eva@example.test', 'token_hash' => str_repeat('b', 64), 'preset' => 'child', 'locale' => 'sk',
        'invited_by' => $synthetic->id, 'expires_at' => now()->addDay(),
    ]);
    DB::table('audit_logs')->insert(['event' => 'invite_created', 'actor_id' => $synthetic->id, 'meta' => json_encode(['email' => 'eva@example.test'])]);

    $this->artisan('staging:audit')->assertExitCode(0);
});
