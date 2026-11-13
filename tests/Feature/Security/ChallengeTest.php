<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('x'));

function challengeUser(array $attrs = []): User
{
    return User::factory()->create(['email' => 'anna@example.test'] + $attrs);
}

function passwordLogin(object $test, array $extra = []): Illuminate\Testing\TestResponse
{
    return $test->post('/login', ['email' => 'anna@example.test', 'password' => 'password'] + $extra);
}

function addPasskeyRow(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'cred-'.$user->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function loginRows(): int
{
    return AuditEntry::where('event', 'login_success')->count();
}

it('challenges a member with an authenticator app instead of signing in', function () {
    $user = challengeUser();
    Totp::enable($user);

    passwordLogin($this)->assertRedirect('/two-factor-challenge');

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id)->and(session('login.at'))->toBeInt();
    expect(loginRows())->toBe(0);

    $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Auth/TwoFactorChallenge', false)
        ->where('totp', true)
        ->where('passkey', false)
        ->missing('id')->missing('email'));
});

it('challenges a passkey-only member too', function () {
    $user = challengeUser();
    addPasskeyRow($user);

    passwordLogin($this)->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertInertia(fn (Assert $page) => $page
        ->where('totp', false)->where('passkey', true));
});

it('does not challenge a member with only a pending authenticator secret', function () {
    $user = challengeUser();
    Totp::enable($user, confirm: false);

    passwordLogin($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('signs in directly without any second factor', function () {
    $user = challengeUser();

    passwordLogin($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(loginRows())->toBe(1);
});

it('finishes sign-in with a valid code, regenerates the session and returns to the sanitised intended path', function () {
    $user = challengeUser();
    $secret = Totp::enable($user);

    $this->get('/finance');
    passwordLogin($this)->assertRedirect('/two-factor-challenge');
    $before = session()->getId();

    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)])->assertRedirect('/finance');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before)->and(loginRows())->toBe(1);
    expect(session()->has('login.id'))->toBeFalse()->and(session()->has('login.at'))->toBeFalse();
});

it('never redirects to a foreign intended address', function () {
    $user = challengeUser();
    $secret = Totp::enable($user);

    passwordLogin($this);
    session()->put('url.intended', 'https://evil.example/steal');

    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)])->assertRedirect('/');
});

it('answers a wrong code with one generic error and one audit row', function () {
    $user = challengeUser();
    Totp::enable($user);
    passwordLogin($this);

    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertRedirect('/two-factor-challenge')
        ->assertSessionHasErrors(['code' => 'twofactor.error.code']);

    $this->assertGuest();
    $row = AuditEntry::where('event', 'second_factor_failed')->sole();
    expect($row->meta)->toMatchArray(['method' => 'totp', 'context' => 'login']);
});

it('accepts a recovery code once and reports how many remain', function () {
    $user = challengeUser();
    Totp::enable($user);
    $codes = Totp::recoveryCodes($user);
    passwordLogin($this);

    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session('toast'))->toBe(['key' => 'twofactor.recovery.used', 'params' => ['n' => 7], 'count' => 7]);
    expect(AuditEntry::where('event', 'recovery_code_used')->count())->toBe(1);

    auth()->logout();
    $this->flushSession();
    passwordLogin($this);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
        ->assertSessionHasErrors(['recovery_code' => 'twofactor.error.recovery']);
});

it('sets the remember cookie after the challenge when requested at the password step', function () {
    $user = challengeUser();
    $secret = Totp::enable($user);

    passwordLogin($this, ['remember' => 'on']);
    $response = $this->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)]);

    $name = auth()->guard('web')->getRecallerName();
    expect(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name))->not->toBeNull();
});

it('refuses the password confirmation endpoint to a signed-in member', function () {
    $user = challengeUser();

    $this->actingAs($user)->post('/user/confirm-password', ['password' => 'password'])->assertForbidden();
});
