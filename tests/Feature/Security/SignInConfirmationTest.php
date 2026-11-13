<?php

use App\Models\User;
use App\Security\SensitiveSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->useRealSensitiveSession();
});

it('opens both windows after a password sign-in without a factor', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    expect(session('sensitive.active_at'))->toBeInt()->and(session('auth.password_confirmed_at'))->toBeInt();
    $session = app(SensitiveSession::class);
    expect($session->fresh())->toBeTrue()->and($session->recentlyConfirmed())->toBeTrue();
});

it('opens both windows after a completed authenticator sign-in', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $secret = Totp::enable($user);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)]);

    expect(session('sensitive.active_at'))->toBeInt()->and(session('auth.password_confirmed_at'))->toBeInt();
});

it('does not open either window when the session is restored from the remember cookie', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $login = $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password', 'remember' => 'on']);
    $name = Auth::guard('web')->getRecallerName();
    $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === $name);

    // A new browser session that only carries the (encrypted) remember cookie.
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    // withUnencryptedCookie: the value is already encrypted by the login response.
    $this->withUnencryptedCookie($name, $cookie->getValue())->get('/')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(session()->has('sensitive.active_at'))->toBeFalse()->and(session()->has('auth.password_confirmed_at'))->toBeFalse();
});
