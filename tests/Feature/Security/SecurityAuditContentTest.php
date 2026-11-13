<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Fortify;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakeGoogle;
use Tests\Support\FakePasskeys;
use Tests\Support\Totp;

// Canary scan (T-06-65): every Phase 6 flow runs with recognisable secrets; afterwards no audit_logs column and
// no log record may contain any of them.

const SAC_PASSKEY_NAME = 'CanaryKeyName-7731';
const SAC_PASSKEY_RENAMED = 'CanaryRenamed-2208';
const SAC_WRONG_PASSWORD = 'CanaryPw-5519';
const SAC_WRONG_CODE = 'CanaryCode-4417';
const SAC_WRONG_RECOVERY = 'CanaryRecovery-9921';

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function sacFresh(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp(), 'sensitive.active_at' => now()->getTimestamp()];
}

function sacStale(): array
{
    return ['auth.password_confirmed_at' => now()->subHour()->getTimestamp(), 'sensitive.active_at' => now()->subHour()->getTimestamp()];
}

function sacSignedOut(object $test): void
{
    app('auth')->forgetGuards();
    $test->flushSession();
    Cache::flush(); // throttles are not under test here
}

it('keeps secrets, codes, names, credentials and passwords out of audit rows and logs across every flow', function () {
    config(['security.google_reauth' => true, 'security.passkeys_enabled' => true]);
    FakeGoogle::configure();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR).' '.
            (($e->context['exception'] ?? null) instanceof Throwable ? $e->context['exception']->getMessage() : '');
    });

    $canaries = [SAC_PASSKEY_NAME, SAC_PASSKEY_RENAMED, SAC_WRONG_PASSWORD, SAC_WRONG_CODE, SAC_WRONG_RECOVERY, FakeGoogle::TOKEN, 'fake-refresh-token-xyz'];

    $admin = User::factory()->admin()->create(['email' => 'boss@example.test']);
    $member = User::factory()->withPreset(Preset::Parent)->create(['email' => 'anna@example.test'])->refresh();

    // --- authenticator: setup, confirm, regenerate ---------------------------------------------------------
    $this->actingAs($member)->withSession(sacFresh())->postJson('/user/two-factor-authentication')->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($member->fresh()->two_factor_secret);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])->assertOk();
    $canaries[] = $secret;
    $canaries = array_merge($canaries, Totp::recoveryCodes($member));
    $this->postJson('/user/two-factor-recovery-codes')->assertOk();
    $firstCodes = Totp::recoveryCodes($member);
    $canaries = array_merge($canaries, $firstCodes);

    // A wrong code on the confirm endpoint (new pending secret after a disable).
    $this->deleteJson('/user/two-factor-authentication')->assertStatus(200);
    $this->postJson('/user/two-factor-authentication')->assertOk();
    $pending = Fortify::currentEncrypter()->decrypt($member->fresh()->two_factor_secret);
    $canaries[] = $pending;
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => SAC_WRONG_CODE]);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($pending)])->assertOk();
    $canaries = array_merge($canaries, Totp::recoveryCodes($member));
    $secret = $pending;

    // --- sign-in challenge: wrong code, wrong recovery code, success, recovery use -------------------------
    sacSignedOut($this);
    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['code' => SAC_WRONG_CODE]);
    $this->post('/two-factor-challenge', ['recovery_code' => SAC_WRONG_RECOVERY]);
    $this->post('/two-factor-challenge', ['code' => Totp::code($secret, -1)]);

    sacSignedOut($this);
    $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['recovery_code' => Totp::recoveryCodes($member)[0]]);

    // --- passkeys: add, rename, challenge (ok and failing), reauth, remove -----------------------------------
    FakePasskeys::registers();
    $this->actingAs($member)->withSession(sacFresh())->getJson('/settings/security/passkeys/options')->assertOk();
    $this->postJson('/settings/security/passkeys', ['name' => SAC_PASSKEY_NAME, 'credential' => FakePasskeys::attestation()])->assertSuccessful();
    $passkey = DB::table('passkeys')->where('user_id', $member->id)->first();
    $canaries[] = $passkey->credential_id;
    $this->patchJson("/settings/security/passkeys/{$passkey->id}", ['name' => SAC_PASSKEY_RENAMED])->assertSuccessful();

    sacSignedOut($this);
    FakePasskeys::verifies();
    $pending = ['login.id' => $member->id, 'login.remember' => false, 'login.at' => now()->getTimestamp()];
    $this->withSession($pending)->getJson('/two-factor-challenge/passkey/options')->assertOk();
    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($passkey->credential_id)])->assertSuccessful();

    sacSignedOut($this);
    FakePasskeys::rejects();
    $this->withSession($pending)->getJson('/two-factor-challenge/passkey/options')->assertOk();
    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($passkey->credential_id)])->assertStatus(422);

    FakePasskeys::verifies();
    $this->actingAs($member)->withSession(sacStale())->getJson('/confirm-access/passkey/options')->assertOk();
    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($passkey->credential_id), 'mode' => 'dialog'])->assertSuccessful();
    FakePasskeys::rejects();
    $this->withSession(sacStale())->getJson('/confirm-access/passkey/options');
    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($passkey->credential_id), 'mode' => 'dialog'])->assertStatus(422);
    $this->withSession(sacFresh())->deleteJson("/settings/security/passkeys/{$passkey->id}")->assertSuccessful();

    // --- /confirm-access: wrong password, wrong code, success ------------------------------------------------
    Cache::flush();
    $this->actingAs($member)->withSession(sacStale())->post('/confirm-access', ['password' => SAC_WRONG_PASSWORD]);
    $this->post('/confirm-access', ['code' => SAC_WRONG_CODE]);
    $this->postJson('/confirm-access', ['password' => 'password'])->assertSuccessful();
    $this->postJson('/confirm-access', ['code' => Totp::code($secret, -2)]);

    // --- admin: policy toggle and reset ----------------------------------------------------------------------
    $this->actingAs($admin)->withSession(sacFresh())->putJson('/settings/security/policy', ['required' => true])->assertSuccessful();
    $this->putJson('/settings/security/policy', ['required' => false])->assertSuccessful();
    $this->deleteJson("/settings/members/{$member->id}/second-factor")->assertSuccessful();

    // --- Google re-auth failure --------------------------------------------------------------------------------
    $googleOnly = User::factory()->googleOnly()->withPreset(Preset::Parent)->create()->refresh();
    FakeGoogle::returns('someone-else-sub', 'other@example.test');
    $this->actingAs($googleOnly)->withSession(sacStale() + ['reauth.google' => ['user' => $googleOnly->id, 'at' => now()->getTimestamp()]])
        ->get('/auth/google/callback');
    FakeGoogle::throws();
    $this->get('/auth/google/callback');

    // --- scan -----------------------------------------------------------------------------------------------------
    $rows = DB::table('audit_logs')->get();
    expect($rows->count())->toBeGreaterThan(10);
    $dump = json_encode($rows->map(fn ($r) => (array) $r)->all());
    $events = $rows->pluck('event')->unique()->all();
    foreach (['two_factor_enabled', 'second_factor_failed', 'passkey_added', 'passkey_renamed', 'passkey_removed', 'reauth_confirmed', 'security_policy_changed', 'second_factor_reset', 'recovery_codes_regenerated'] as $needed) {
        expect($events)->toContain($needed);
    }

    $haystack = $dump."\n".implode("\n", $logged);
    foreach (array_unique($canaries) as $canary) {
        expect(str_contains($haystack, (string) $canary))->toBeFalse("canary leaked: {$canary}");
    }
    // Column names of the encrypted factor never appear in audit or logs.
    expect(str_contains($haystack, 'two_factor_secret'))->toBeFalse()
        ->and(str_contains($haystack, 'two_factor_recovery_codes'))->toBeFalse();
});
