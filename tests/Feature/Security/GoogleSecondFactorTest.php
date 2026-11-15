<?php

use App\Auth\InviteService;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Security\SensitiveSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\Support\FakeGoogle;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeGoogle::configure();
    $this->useRealSensitiveSession();
});

function linkedMember(string $sub = 'g-sub-1'): User
{
    return User::factory()->create(['google_id' => $sub])->refresh();
}

it('challenges a member with an authenticator app instead of signing in', function () {
    $user = linkedMember();
    $secret = Totp::enable($user);
    FakeGoogle::returns('g-sub-1', $user->email);

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id)->and(session('login.remember'))->toBeFalse();
    expect(AuditEntry::where('event', 'login_success')->count())->toBe(0);

    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, -1)])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect(AuditEntry::where('event', 'login_success')->count())->toBe(1);
});

it('challenges a passkey-only member too', function () {
    $user = linkedMember();
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'c-'.$user->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    FakeGoogle::returns('g-sub-1', $user->email);

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

it('signs in a member without a factor, opens the window and returns to the intended page', function () {
    $user = linkedMember();
    FakeGoogle::returns('g-sub-1', $user->email);

    $this->withSession(['url.intended' => '/finance'])->get('/auth/google/callback')->assertRedirect('/finance');
    $this->assertAuthenticatedAs($user);
    expect(app(SensitiveSession::class)->fresh())->toBeTrue()->and(app(SensitiveSession::class)->recentlyConfirmed())->toBeTrue();
});

it('falls back to the home page without an intended url', function () {
    $user = linkedMember();
    FakeGoogle::returns('g-sub-1', $user->email);

    $this->get('/auth/google/callback')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('maintains password_set for Google invites, password invites and resets', function () {
    $service = app(InviteService::class);
    $make = fn (string $email, string $tok) => Invitation::create([
        'email' => $email, 'token_hash' => hash('sha256', $tok), 'preset' => 'child', 'locale' => 'en', 'expires_at' => now()->addDays(7),
    ]);

    $viaGoogle = $service->accept($make('a@example.test', 't1'), ['name' => 'A'], 'g-new');
    expect($viaGoogle->refresh()->password_set)->toBeFalse();

    $viaPassword = $service->accept($make('b@example.test', 't2'), ['name' => 'B', 'password' => 'a-long-enough-pass-1']);
    expect($viaPassword->refresh()->password_set)->toBeTrue();

    $token = Password::broker()->createToken($viaGoogle);
    $this->post('/reset-password', [
        'token' => $token, 'email' => $viaGoogle->email, 'password' => 'Zq7!lmnop-unique-pass', 'password_confirmation' => 'Zq7!lmnop-unique-pass',
    ]);
    expect($viaGoogle->refresh()->password_set)->toBeTrue();
});
