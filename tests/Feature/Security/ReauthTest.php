<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Security\ReauthMethods;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function reauthUser(array $state = []): User
{
    return User::factory()->withPreset(Preset::Parent)->create($state)->refresh();
}

function reauthRows(string $event)
{
    return AuditEntry::where('event', $event)->get();
}

function reauthPasskey(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'c-'.$user->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('shows the methods, the idle minutes and the interrupted flag', function () {
    $user = reauthUser();
    $this->actingAs($user)->get('/confirm-access')->assertInertia(fn (Assert $p) => $p
        ->component('Auth/ConfirmAccess')
        ->where('methods', ['password' => true, 'totp' => false, 'passkey' => false, 'google' => false])
        ->where('minutes', 15)->where('interrupted', false));

    Totp::enable($user);
    $this->actingAs($user)->withSession(['status' => 'reauth_interrupted'])->get('/confirm-access')
        ->assertInertia(fn (Assert $p) => $p->where('methods.totp', true)->where('interrupted', true));
});

it('offers Google re-auth only behind the flag and only without a stronger method', function () {
    $user = User::factory()->googleOnly()->create()->refresh();
    expect(ReauthMethods::for($user))->toBe(['password' => false, 'totp' => false, 'passkey' => false, 'google' => false]);

    config(['security.google_reauth' => true]);
    expect(ReauthMethods::googleAvailable($user))->toBeTrue();

    $withPassword = User::factory()->googleOnly()->create(['password_set' => true])->refresh();
    expect(ReauthMethods::googleAvailable($withPassword))->toBeFalse();

    $withTotp = User::factory()->googleOnly()->create()->refresh();
    Totp::enable($withTotp);
    expect(ReauthMethods::googleAvailable($withTotp))->toBeFalse();

    $withPasskey = User::factory()->googleOnly()->create()->refresh();
    reauthPasskey($withPasskey);
    expect(ReauthMethods::googleAvailable($withPasskey))->toBeFalse();
});

it('confirms with the password, returns to the intended page and audits it', function () {
    $user = reauthUser();

    $this->actingAs($user)->withSession(['url.intended' => '/finance/transactions'])
        ->post('/confirm-access', ['password' => 'password'])->assertStatus(303)->assertRedirect('/finance/transactions');

    $this->get('/finance')->assertOk();
    $rows = reauthRows('reauth_confirmed');
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'password']);
});

it('rejects a wrong password with a generic error and an audit row', function () {
    $user = reauthUser();

    $this->actingAs($user)->post('/confirm-access', ['password' => 'nope'])
        ->assertSessionHasErrors(['password' => 'reauth.error.password']);

    $row = reauthRows('second_factor_failed')->first();
    expect($row->meta)->toBe(['method' => 'password', 'context' => 'reauth']);
    $this->get('/finance')->assertRedirect('/confirm-access');
});

it('refuses the password method to a member without a password', function () {
    $user = User::factory()->googleOnly()->create()->refresh();

    $this->actingAs($user)->post('/confirm-access', ['password' => 'password'])
        ->assertSessionHasErrors(['password' => 'reauth.error.password']);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('confirms with the authenticator code and refuses a replay', function () {
    $user = reauthUser();
    $secret = Totp::enable($user);
    // Offset -1: a past slot is rejected on replay (Fortify stores the current slot as the replay marker).
    $code = Totp::code($secret, -1);

    $this->actingAs($user)->post('/confirm-access', ['code' => $code])->assertStatus(303);
    expect(reauthRows('reauth_confirmed')->first()->meta)->toBe(['method' => 'totp']);

    $this->post('/confirm-access', ['code' => $code])->assertSessionHasErrors(['code' => 'twofactor.error.code']);
});

it('rejects a wrong or unavailable authenticator code', function () {
    $user = reauthUser();
    $this->actingAs($user)->post('/confirm-access', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'twofactor.error.code']);

    Totp::enable($user);
    $this->actingAs($user)->post('/confirm-access', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'twofactor.error.code']);
    expect(reauthRows('second_factor_failed'))->toHaveCount(2);
});

it('rejects recovery codes, arrays and both credentials with 422', function () {
    $user = reauthUser();
    Totp::enable($user);
    $recovery = Totp::recoveryCodes($user)[0];

    $this->actingAs($user)->post('/confirm-access', ['recovery_code' => $recovery])->assertStatus(422);
    $this->post('/confirm-access', ['password' => ['x']])->assertStatus(422);
    $this->post('/confirm-access', ['code' => ['x']])->assertStatus(422);
    $this->post('/confirm-access', ['password' => 'password', 'code' => '123456'])->assertStatus(422);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('never follows a tampered intended address', function () {
    $this->actingAs(reauthUser())->withSession(['url.intended' => 'https://evil.example/x'])
        ->post('/confirm-access', ['password' => 'password'])->assertRedirect('/');
});

it('answers JSON callers with 200 or 422, leaves intended alone and opens the 10 minute window', function () {
    $user = reauthUser();
    $stale = now()->subMinutes(11)->getTimestamp();

    $this->actingAs($user)->withSession(['url.intended' => '/finance', 'auth.password_confirmed_at' => $stale]);

    $this->postJson('/confirm-access', ['password' => 'bad'])->assertStatus(422)
        ->assertExactJson(['errors' => ['password' => 'reauth.error.password']]);

    $this->postJson('/confirm-access', ['password' => 'password'])->assertOk()->assertExactJson(['confirmed' => true])
        ->assertSessionHas('url.intended', '/finance');
    expect(session('auth.password_confirmed_at'))->toBeGreaterThan(now()->subMinute()->getTimestamp());
    $this->actingAs($user)->get('/confirm-access')->assertOk();
});

it('throttles the sixth attempt within a minute', function () {
    $user = reauthUser();
    $this->actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/confirm-access', ['password' => 'bad'])->assertSessionHasErrors('password');
    }
    $this->post('/confirm-access', ['password' => 'bad'])->assertSessionHasErrors('throttle');

    $this->postJson('/confirm-access', ['password' => 'bad'])->assertStatus(429)->assertJsonStructure(['error', 'minutes']);
});

it('signs the member out, keeps the intended address and clears the sensitive windows', function () {
    $user = reauthUser();

    $this->actingAs($user)->withSession([
        'url.intended' => '/finance', 'sensitive.active_at' => time(), 'auth.password_confirmed_at' => time(),
    ])->post('/confirm-access/sign-in-again')->assertStatus(303)->assertRedirect('/login')
        ->assertSessionHas('url.intended', '/finance')
        ->assertSessionMissing('sensitive.active_at')->assertSessionMissing('auth.password_confirmed_at');

    $this->assertGuest();
});
