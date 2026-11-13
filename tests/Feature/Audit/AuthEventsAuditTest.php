<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Password;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('x'));

it('records login_success with the actor', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password'])->assertRedirect('/');

    $row = AuditEntry::sole();
    expect($row->event)->toBe('login_success')->and($row->actor_id)->toBe($user->id);
});

it('records login_failed with the actor for an existing user', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'wrong-password']);

    $row = AuditEntry::sole();
    expect($row->event)->toBe('login_failed')->and($row->actor_id)->toBe($user->id);
});

it('records login_failed without actor and without the typed email for an unknown email', function () {
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);

    $row = AuditEntry::sole();
    expect($row->event)->toBe('login_failed')->and($row->actor_id)->toBeNull();
    expect(json_encode($row->getAttributes()))->not->toContain('nobody')->not->toContain('wrong-password');
});

it('records lockout, storing only the id of a matching user', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);

    event(new Lockout(Request::create('/login', 'POST', ['email' => 'ANNA@example.test'])));
    event(new Lockout(Request::create('/login', 'POST', ['email' => 'ghost@example.test'])));

    $rows = AuditEntry::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->event)->toBe('lockout')->and($rows[0]->actor_id)->toBe($user->id)
        ->and($rows[1]->actor_id)->toBeNull();
    expect(json_encode($rows->map->getAttributes()))->not->toContain('ghost');
});

it('records password_reset once, inside the reset action', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => 'anna@example.test',
        'password' => 'a long new passphrase', 'password_confirmation' => 'a long new passphrase',
    ])->assertRedirect('/login');

    $row = AuditEntry::sole();
    expect($row->event)->toBe('password_reset')->and($row->actor_id)->toBe($user->id);
});

it('flags a remembered login in the session', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password', 'remember' => '1']);

    expect(session('auth_remember'))->toBeTrue();
});

it('does not flag a login without remember', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    expect(session('auth_remember'))->toBeNull();
});
