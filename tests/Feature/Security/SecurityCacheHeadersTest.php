<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakePasskeys;
use Tests\Support\Totp;

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
    config(['security.passkeys_enabled' => true]);
});

function schFresh(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp(), 'sensitive.active_at' => now()->getTimestamp()];
}

function schStale(): array
{
    return ['auth.password_confirmed_at' => now()->subHour()->getTimestamp(), 'sensitive.active_at' => now()->subHour()->getTimestamp()];
}

function schNoStore($response, string $what): void
{
    expect(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'))->toBeTrue("{$what} answered {$response->getStatusCode()} without no-store");
}

it('marks the security pages and their guarded endpoints no-store', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test'])->refresh();

    schNoStore($this->actingAs($user)->withSession(schFresh())->get('/settings/security'), 'settings security');
    schNoStore($this->get('/confirm-access'), 'confirm access');

    // pending setup: secret key and QR code, then enable/confirm/regenerate JSON
    schNoStore($this->postJson('/user/two-factor-authentication')->assertOk(), 'enable');
    schNoStore($this->getJson('/user/two-factor-qr-code')->assertOk(), 'qr code');
    schNoStore($this->getJson('/user/two-factor-secret-key')->assertOk(), 'secret key');
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    schNoStore($this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])->assertOk(), 'confirm');
    schNoStore($this->postJson('/user/two-factor-recovery-codes')->assertOk(), 'regenerate');
    schNoStore($this->deleteJson('/user/two-factor-authentication'), 'disable');
});

it('marks the two-step challenge, even for a pending login, no-store', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    Totp::enable($user);
    app('auth')->forgetGuards();

    $login = $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);
    schNoStore($login, 'login redirect');
    schNoStore($this->get('/two-factor-challenge')->assertOk(), 'challenge page');
    schNoStore($this->post('/two-factor-challenge', ['code' => 'wrong']), 'challenge failure');
});

it('marks every passkey options endpoint no-store', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test'])->refresh();
    $id = Base64UrlSafe::encodeUnpadded('cred-'.$user->id);
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => $id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    FakePasskeys::verifies();

    schNoStore($this->actingAs($user)->withSession(schFresh())->getJson('/settings/security/passkeys/options')->assertOk(), 'passkey registration options');
    schNoStore($this->withSession(schStale())->getJson('/confirm-access/passkey/options')->assertOk(), 'reauth options');
    schNoStore($this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($id), 'mode' => 'dialog'])->assertSuccessful(), 'reauth passkey');

    app('auth')->forgetGuards();
    $this->flushSession();
    Cache::flush();
    $pending = ['login.id' => $user->id, 'login.remember' => false, 'login.at' => now()->getTimestamp()];
    schNoStore($this->withSession($pending)->getJson('/two-factor-challenge/passkey/options')->assertOk(), 'challenge passkey options');
});

it('marks the 423 refusals no-store', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create()->refresh();
    $admin = User::factory()->admin()->create();

    schNoStore($this->actingAs($user)->withSession(schStale())->postJson('/user/two-factor-authentication')->assertStatus(423), 'enable 423');
    schNoStore($this->getJson('/settings/security/passkeys/options')->assertStatus(423), 'passkey options 423');
    schNoStore($this->actingAs($admin)->withSession(schStale())->putJson('/settings/security/policy', ['required' => true])->assertStatus(423), 'policy 423');
    schNoStore($this->actingAs($user)->withSession(schStale())->getJson('/finance')->assertStatus(423), 'idle gate 423');
});
