<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('x'));

it('renders the login page for guests', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('googleEnabled', false)
        ->where('auth.user', null));
});

it('signs in with correct credentials, regenerates the session and lands on home', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password'])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

it('matches the email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'ANNA@EXAMPLE.TEST', 'password' => 'password'])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('returns the identical error for a wrong password and an unknown email', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $wrong = $this->from('/login')->post('/login', ['email' => 'anna@example.test', 'password' => 'nope-nope-nope']);
    $unknown = $this->from('/login')->post('/login', ['email' => 'ghost@example.test', 'password' => 'nope-nope-nope']);

    $wrong->assertRedirect('/login')->assertSessionHasErrors(['email' => 'auth.error.failed']);
    $unknown->assertRedirect('/login')->assertSessionHasErrors(['email' => 'auth.error.failed']);
    $this->assertGuest();
});

it('throttles the 6th attempt within a minute with a minute count and fires Lockout', function () {
    Event::fake([Lockout::class]);
    User::factory()->create(['email' => 'anna@example.test']);

    foreach (range(1, 5) as $i) {
        $this->from('/login')->post('/login', ['email' => 'anna@example.test', 'password' => 'wrong-wrong-wrong'])
            ->assertSessionHasErrors('email');
    }
    $response = $this->from('/login')->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    $response->assertRedirect('/login')->assertSessionHasErrors('throttle');
    expect((int) session('errors')->first('throttle'))->toBeGreaterThanOrEqual(1);
    $this->assertGuest();
    Event::assertDispatched(Lockout::class);
});

it('throttles one email across different IPs after 10 failures in 15 minutes', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    foreach (range(1, 10) as $i) {
        $this->from('/login')->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])
            ->post('/login', ['email' => 'anna@example.test', 'password' => 'wrong-wrong-wrong'])
            ->assertSessionHasErrors('email');
    }

    $this->from('/login')->withServerVariables(['REMOTE_ADDR' => '10.1.0.99'])
        ->post('/login', ['email' => 'anna@example.test', 'password' => 'password'])
        ->assertSessionHasErrors('throttle');
    $this->assertGuest();
});

it('redirects a guest from home to /login and a signed-in user away from /login', function () {
    $this->get('/')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('logs out to /login with the signed_out status', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertStatus(303)
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'signed_out');

    $this->assertGuest();
});

it('shares auth.user and flash.status with signed-in pages and never leaks secrets', function () {
    $user = User::factory()->admin()->create(['email' => 'anna@example.test']);

    $this->actingAs($user)->withSession(['status' => 'signed_out'])->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.email', 'anna@example.test')
        ->where('auth.user.isAdmin', true)
        ->missing('auth.user.password')
        ->missing('auth.user.remember_token')
        ->missing('auth.user.google_id')
        ->where('flash.status', 'signed_out'));
});

it('stores the locale on the account and uses it without a cookie', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/locale', ['locale' => 'pl'])->assertStatus(303);
    expect($user->refresh()->locale)->toBe('pl');

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'pl'));
});
