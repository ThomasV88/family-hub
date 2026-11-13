<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->useRealSensitiveSession();
});

it('opens Finances right after a password sign-in without a factor', function () {
    User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    $this->get('/finance')->assertOk();
});

it('opens Finances right after the authenticator challenge', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test']);
    $secret = Totp::enable($user);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)]);

    $this->get('/finance')->assertOk();
});

it('asks a remember-cookie restore to confirm first, then returns to Finances', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test']);
    $login = $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password', 'remember' => 'on']);
    $name = Auth::guard('web')->getRecallerName();
    $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === $name);

    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->withUnencryptedCookie($name, $cookie->getValue());

    $this->get('/finance')->assertStatus(302)->assertRedirect('/confirm-access');
    $this->assertAuthenticatedAs($user);

    $this->post('/confirm-access', ['password' => 'password'])->assertRedirect('/finance');
    $this->get('/finance')->assertOk();
});
