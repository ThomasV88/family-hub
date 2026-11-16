<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakeGoogle;
use Tests\Support\FakePasskeys;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

// Strongest subject (research Pitfall 5): a real e-mail and a known password, only sign_in_disabled set. Every
// session-establishing path must still refuse it, each next to a normal-member control row.

beforeEach(function () {
    RateLimiter::clear('x');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function lockedSubject(array $extra = []): User
{
    return User::factory()->create([
        'email' => 'locked@example.test', 'password' => Hash::make('known-pass-123'), 'sign_in_disabled' => true,
    ] + $extra);
}

function normalSubject(array $extra = []): User
{
    return User::factory()->create([
        'email' => 'normal@example.test', 'password' => Hash::make('known-pass-123'),
    ] + $extra);
}

function lockedPasskey(User $user): string
{
    $id = Base64UrlSafe::encodeUnpadded('cred-'.$user->id);
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => $id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function pendingFor(User $user): array
{
    return ['login.id' => $user->id, 'login.remember' => false, 'login.at' => now()->getTimestamp()];
}

describe('password', function () {
    it('refuses the right password of a disabled account with the same error as a wrong one', function () {
        lockedSubject();

        $locked = $this->from('/login')->post('/login', ['email' => 'locked@example.test', 'password' => 'known-pass-123']);
        $locked->assertRedirect('/login')->assertSessionHasErrors(['email' => 'auth.error.failed']);
        $this->assertGuest();
    });

    it('still signs a normal member in', function () {
        $user = normalSubject();

        $this->post('/login', ['email' => 'normal@example.test', 'password' => 'known-pass-123'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    });

    it('never reaches the second-factor challenge for a disabled account', function () {
        $user = lockedSubject();
        Totp::enable($user);

        $this->from('/login')->post('/login', ['email' => 'locked@example.test', 'password' => 'known-pass-123'])
            ->assertRedirect('/login');

        expect(session()->has('login.id'))->toBeFalse();
        $this->assertGuest();
    });
});

describe('two-factor challenge', function () {
    it('refuses a forged login.id of a disabled account on the page and on the code post', function () {
        $user = lockedSubject();
        $secret = Totp::enable($user);

        $this->withSession(pendingFor($user))->get('/two-factor-challenge')->assertRedirect('/login');
        expect(session()->has('login.id'))->toBeFalse();

        $this->withSession(pendingFor($user))->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)])
            ->assertRedirect('/login')->assertSessionHas('status', 'challenge_expired');
        $this->assertGuest();
    });

    it('refuses a forged login.id of an account that does not exist', function () {
        $this->withSession(['login.id' => 999999, 'login.remember' => false, 'login.at' => now()->getTimestamp()])
            ->get('/two-factor-challenge')->assertRedirect('/login');
        $this->assertGuest();
    });

    it('lets a normal member through the code step', function () {
        $user = normalSubject();
        $secret = Totp::enable($user);

        $this->withSession(pendingFor($user))->post('/two-factor-challenge', ['code' => Totp::code($secret, 1)])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    });
});

describe('passkey second step', function () {
    it('refuses a forged login.id of a disabled account and creates no session', function () {
        $user = lockedSubject();
        $id = lockedPasskey($user);
        FakePasskeys::verifies();

        $this->withSession(pendingFor($user))->getJson('/two-factor-challenge/passkey/options')->assertStatus(410);
        $this->withSession(pendingFor($user))->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])
            ->assertStatus(410);

        $this->assertGuest();
    });

    it('still offers options to a normal member', function () {
        $user = normalSubject();
        lockedPasskey($user);

        $this->withSession(pendingFor($user))->getJson('/two-factor-challenge/passkey/options')->assertOk();
    });
});

describe('google', function () {
    beforeEach(fn () => FakeGoogle::configure());

    it('refuses a disabled account matched by its google id', function () {
        lockedSubject(['google_id' => 'sub-locked']);
        FakeGoogle::returns('sub-locked', 'locked@example.test');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');

        $this->assertGuest();
    });

    it('refuses a disabled account matched by e-mail linking and does not link it', function () {
        $user = lockedSubject();
        FakeGoogle::returns('sub-new', 'locked@example.test');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');

        $this->assertGuest();
        expect($user->refresh()->google_id)->toBeNull();
    });

    it('still signs a normal member in by google id', function () {
        $user = normalSubject(['google_id' => 'sub-normal']);
        FakeGoogle::returns('sub-normal', 'normal@example.test');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    });
});

describe('password reset', function () {
    it('answers a disabled account exactly like an unknown address and sends no mail', function () {
        Notification::fake();
        lockedSubject();

        $locked = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'locked@example.test']);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ghost@example.test']);

        expect($locked->getStatusCode())->toBe($unknown->getStatusCode())
            ->and($locked->headers->get('Location'))->toBe($unknown->headers->get('Location'));
        $locked->assertSessionHas('status', 'reset_link_sent');
        $unknown->assertSessionHas('status', 'reset_link_sent');
        Notification::assertNothingSent();
    });

    it('does not change the password of a disabled account even with a valid token', function () {
        $user = lockedSubject();
        $hash = $user->password;
        $token = Password::broker()->createToken($user);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token, 'email' => 'locked@example.test',
            'password' => 'a long new passphrase', 'password_confirmation' => 'a long new passphrase',
        ])->assertSessionHasErrors(['email' => 'auth.reset.invalid_body']);

        expect($user->refresh()->password)->toBe($hash);
        $this->assertGuest();
    });

    it('still resets the password of a normal member', function () {
        $user = normalSubject();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'normal@example.test',
            'password' => 'a long new passphrase', 'password_confirmation' => 'a long new passphrase',
        ])->assertRedirect('/login');

        expect(Hash::check('a long new passphrase', $user->refresh()->password))->toBeTrue();
    });
});

describe('live sessions', function () {
    it('logs out an existing session of an account that cannot sign in', function () {
        $user = lockedSubject();

        $this->actingAs($user)->get('/')->assertRedirect('/login');

        $this->assertGuest();
    });

    it('answers a JSON request of a disabled session with 401', function () {
        $user = lockedSubject();

        $this->actingAs($user)->getJson('/')->assertStatus(401);
    });

    it('logs out a remember-cookie login of an account disabled afterwards', function () {
        $user = normalSubject();
        $response = $this->post('/login', ['email' => 'normal@example.test', 'password' => 'known-pass-123', 'remember' => 'on']);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName());
        expect($cookie)->not->toBeNull();

        $user->forceFill(['sign_in_disabled' => true])->save();
        $this->flushSession();
        app('auth')->forgetGuards();

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    });

    it('leaves guests and normal members untouched', function () {
        $this->get('/login')->assertOk();
        $this->actingAs(normalSubject())->get('/')->assertOk();
        $this->assertAuthenticated();
    });
});
