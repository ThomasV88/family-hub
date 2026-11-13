<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('x');
    $this->useRealSensitiveSession();
});

function stale(): array
{
    return ['auth.password_confirmed_at' => now()->subMinutes(11)->getTimestamp()];
}

function freshWindow(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp()];
}

function auditCount(string $event): int
{
    return AuditEntry::where('event', $event)->count();
}

// --- enrolment recency (Blocker 1) ---------------------------------------------------------

it('refuses to start setup on a stale window and stores nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(stale())->postJson('/user/two-factor-authentication')
        ->assertStatus(423)->assertJson(['error' => 'confirm_required']);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('redirects a stale non-JSON enable to the confirm page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(stale())->post('/user/two-factor-authentication')->assertRedirect('/confirm-access');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('refuses to confirm setup on a stale window', function () {
    $user = User::factory()->create();
    $secret = Totp::enable($user, confirm: false);

    $this->actingAs($user)->withSession(stale())->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])
        ->assertStatus(423);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
    expect(auditCount('two_factor_enabled'))->toBe(0);
});

it('lets a freshly confirmed session enrol and confirm', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(freshWindow())->postJson('/user/two-factor-authentication')->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $this->actingAs($user)->withSession(freshWindow())->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])
        ->assertOk();
});

it('lets a real password sign-in enrol straight away', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    $this->postJson('/user/two-factor-authentication')->assertOk()->assertJson(['enabled' => true]);
});

// --- the JSON flow ---------------------------------------------------------------------------

it('runs the whole setup flow and then closes the secret routes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(freshWindow());

    $this->postJson('/user/two-factor-authentication')->assertOk()->assertExactJson(['enabled' => true]);
    $qr = $this->getJson('/user/two-factor-qr-code')->assertOk();
    expect($qr->headers->get('Cache-Control'))->toContain('no-store')->and($qr->json('svg'))->toContain('<svg');
    $key = $this->getJson('/user/two-factor-secret-key')->assertOk();
    expect($key->headers->get('Cache-Control'))->toContain('no-store');

    $confirmed = $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($key->json('secretKey'))])->assertOk();
    expect($confirmed->json('recoveryCodes'))->toHaveCount(8)->each->toBeString();
    expect($confirmed->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect(auditCount('two_factor_enabled'))->toBe(1);

    $this->getJson('/user/two-factor-qr-code')->assertNotFound();
    $this->getJson('/user/two-factor-secret-key')->assertNotFound();
    $this->postJson('/user/two-factor-authentication')->assertStatus(409)->assertJson(['error' => 'already_enabled']);
    $this->postJson('/user/two-factor-authentication', ['force' => true])->assertStatus(409);
});

it('rejects a wrong confirm code, an array code and throttles the 6th attempt', function () {
    $user = User::factory()->create();
    Totp::enable($user, confirm: false);
    $this->actingAs($user)->withSession(freshWindow());

    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertStatus(422);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => ['1', '2']])->assertStatus(422);
    foreach (range(1, 4) as $i) {
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertStatus(422);
    }

    $r = $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertStatus(429)->assertJsonPath('error', 'throttle');
    expect($r->json('minutes'))->toBeGreaterThanOrEqual(1);
});

it('never lists recovery codes through the Fortify GET route', function () {
    $user = User::factory()->create();
    Totp::enable($user);

    $this->actingAs($user)->getJson('/user/two-factor-recovery-codes')->assertForbidden();
});

// --- regenerate --------------------------------------------------------------------------------

it('regenerates recovery codes only on a fresh window and only with a confirmed app', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $old = Totp::recoveryCodes($user);
    $this->actingAs($user);

    $this->withSession(stale())->postJson('/user/two-factor-recovery-codes')->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    expect(auditCount('recovery_codes_regenerated'))->toBe(0);

    $new = $this->withSession(freshWindow())->postJson('/user/two-factor-recovery-codes')->assertOk();
    expect($new->json('recoveryCodes'))->toHaveCount(8)->and($new->headers->get('Cache-Control'))->toContain('no-store');
    expect(auditCount('recovery_codes_regenerated'))->toBe(1);

    // The old codes no longer sign in.
    auth()->logout();
    $this->flushSession();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['recovery_code' => $old[0]])->assertSessionHasErrors(['recovery_code' => 'twofactor.error.recovery']);
    $this->assertGuest();
});

it('answers 404 to regenerate when no authenticator app is confirmed', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(freshWindow())->postJson('/user/two-factor-recovery-codes')->assertNotFound();

    Totp::enable($user, confirm: false);
    $this->actingAs($user)->withSession(freshWindow())->postJson('/user/two-factor-recovery-codes')->assertNotFound();
});

// --- disable -----------------------------------------------------------------------------------

it('cancels a pending setup without a confirmation and without an audit row', function () {
    $user = User::factory()->create();
    Totp::enable($user, confirm: false);

    $this->actingAs($user)->withSession(stale())->deleteJson('/user/two-factor-authentication')->assertOk()->assertExactJson(['disabled' => true]);

    expect($user->fresh()->two_factor_secret)->toBeNull();
    expect(auditCount('two_factor_disabled'))->toBe(0);
});

it('turns a confirmed app off only on a fresh window, with one audit row', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $this->actingAs($user);

    $this->withSession(stale())->deleteJson('/user/two-factor-authentication')->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    expect($user->fresh()->two_factor_secret)->not->toBeNull()->and(auditCount('two_factor_disabled'))->toBe(0);

    $this->withSession(freshWindow())->deleteJson('/user/two-factor-authentication')->assertOk();
    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()->and($fresh->two_factor_recovery_codes)->toBeNull()->and($fresh->two_factor_confirmed_at)->toBeNull();
    expect(auditCount('two_factor_disabled'))->toBe(1);
});

// --- challenge lifetime and limits ---------------------------------------------------------------

it('expires a pending challenge after ten minutes', function () {
    $user = User::factory()->create();
    Totp::enable($user);

    $this->withSession(['login.id' => $user->id, 'login.at' => now()->subSeconds(601)->getTimestamp()])
        ->get('/two-factor-challenge')->assertRedirect('/login')->assertSessionHas('status', 'challenge_expired');
    expect(session()->has('login.id'))->toBeFalse();

    $this->withSession(['login.id' => $user->id, 'login.at' => now()->subSeconds(599)->getTimestamp()])
        ->get('/two-factor-challenge')->assertOk();
});

it('expires a pending challenge that has no timestamp, and answers JSON callers with 410', function () {
    $user = User::factory()->create();
    Totp::enable($user);

    $this->withSession(['login.id' => $user->id])->postJson('/two-factor-challenge', ['code' => '123456'])
        ->assertStatus(410)->assertJson(['error' => 'challenge_expired']);

    Route::middleware('web')->post('/two-factor-challenge/probe', fn () => 'ok')->middleware('access:public')->name('two-factor.passkey.probe');
    $this->withSession(['login.id' => $user->id, 'login.at' => now()->subHour()->getTimestamp()])
        ->postJson('/two-factor-challenge/probe')->assertStatus(410);
});

it('clears a pending login when the member goes back to sign in', function () {
    $this->withSession(['login.id' => 5, 'login.at' => time(), 'login.remember' => true])->get('/login')->assertOk();

    expect(session()->has('login.id'))->toBeFalse()->and(session()->has('login.at'))->toBeFalse();
});

it('rejects array-valued codes at the challenge', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $this->withSession(['login.id' => $user->id, 'login.at' => time()]);

    $this->postJson('/two-factor-challenge', ['code' => ['a']])->assertStatus(422);
    $this->postJson('/two-factor-challenge', ['recovery_code' => ['a']])->assertStatus(422);
});

it('throttles the sixth wrong code per member even across new sessions, and audits one lockout', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $pending = ['login.id' => $user->id, 'login.at' => time()];

    foreach (range(1, 5) as $i) {
        $this->withSession($pending)->post('/two-factor-challenge', ['code' => '00000'.$i])->assertSessionHasErrors('code');
    }
    $this->withSession($pending)->post('/two-factor-challenge', ['code' => '000009'])->assertSessionHasErrors('throttle');

    $this->flushSession();
    $this->withSession($pending)->post('/two-factor-challenge', ['code' => '000010'])->assertSessionHasErrors('throttle');

    $rows = AuditEntry::where('event', 'lockout')->get();
    expect($rows->count())->toBeGreaterThanOrEqual(1)->and($rows->first()->actor_id)->toBe($user->id);
});

it('does not crash or accept a code for a passkey-only member', function () {
    $user = User::factory()->create();
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'c1', 'credential' => json_encode(['x' => 1]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Totp::enable($user, confirm: false); // a pending secret must not be a way in
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

    $this->withSession(['login.id' => $user->id, 'login.at' => time()])
        ->post('/two-factor-challenge', ['code' => Totp::code($secret)])->assertSessionHasErrors('code');
    $this->withSession(['login.id' => $user->id, 'login.at' => time()])
        ->post('/two-factor-challenge', ['recovery_code' => 'AAAAA-BBBBB'])->assertSessionHasErrors();

    $this->assertGuest();
});
