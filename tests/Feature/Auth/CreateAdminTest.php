<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

it('creates the first admin with a hashed password and lowercased email', function () {
    $this->artisan('family:create-admin', ['email' => 'Anna@Example.Test', '--name' => 'Anna'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertExitCode(0);

    $user = User::sole();
    expect($user->is_admin)->toBeTrue()
        ->and($user->email)->toBe('anna@example.test')
        ->and($user->name)->toBe('Anna')
        ->and($user->locale)->toBe('sk')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('audits admin creation without an actor', function () {
    $this->artisan('family:create-admin', ['email' => 'anna@example.test'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertExitCode(0);

    $row = AuditEntry::sole();
    expect($row->event)->toBe('admin_created')->and($row->actor_id)->toBeNull()->and($row->target_user_id)->toBe(User::sole()->id);
});

it('honours --locale', function () {
    $this->artisan('family:create-admin', ['email' => 'a@example.test', '--locale' => 'pl'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertExitCode(0);

    expect(User::sole()->locale)->toBe('pl');
});

it('refuses a second admin', function () {
    User::factory()->admin()->create();

    $this->artisan('family:create-admin', ['email' => 'b@example.test'])
        ->expectsOutput('An admin already exists.')
        ->assertExitCode(1);

    expect(User::count())->toBe(1);
});

it('rejects a short password and creates nothing', function () {
    $this->artisan('family:create-admin', ['email' => 'c@example.test'])
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('rejects a mismatched confirmation', function () {
    $this->artisan('family:create-admin', ['email' => 'd@example.test'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-batterz')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('does not accept the password as an option', function () {
    $this->artisan('family:create-admin', ['email' => 'e@example.test', '--password' => 'correct-horse-battery']);
})->throws(InvalidOptionException::class);

it('enforces case-insensitive email uniqueness in the database', function () {
    User::factory()->create(['email' => 'same@example.test']);

    expect(fn () => User::factory()->create(['email' => 'SAME@example.test']))->toThrow(QueryException::class);
});

it('does not mass-assign is_admin, google_id or locale', function () {
    $user = User::create([
        'name' => 'X', 'email' => 'x@example.test', 'password' => 'correct-horse-battery',
        'is_admin' => true, 'google_id' => 'g-1', 'locale' => 'pl',
    ]);

    $user->refresh();
    expect($user->is_admin)->toBeFalse()->and($user->google_id)->toBeNull()->and($user->locale)->toBeNull();
});
