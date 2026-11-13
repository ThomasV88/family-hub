<?php

use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Security\PasswordSetBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

function addPasskey(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id,
        'name' => 'Laptop',
        'credential_id' => 'cred-'.$user->id,
        'credential' => json_encode(['x' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function auditRow(string $event, ?int $actor = null, ?int $target = null): void
{
    DB::table('audit_logs')->insert(['event' => $event, 'actor_id' => $actor, 'target_user_id' => $target]);
}

it('knows whether a member has any second factor', function () {
    $user = User::factory()->create();
    expect($user->hasSecondFactor())->toBeFalse();

    Totp::enable($user, confirm: false);
    expect($user->fresh()->hasSecondFactor())->toBeFalse();

    $user = User::factory()->create();
    Totp::enable($user);
    expect($user->fresh()->hasSecondFactor())->toBeTrue();

    $other = User::factory()->create();
    addPasskey($other);
    expect($other->fresh()->hasSecondFactor())->toBeTrue();
});

it('cascades passkeys when the user is deleted', function () {
    $user = User::factory()->create();
    addPasskey($user);
    $user->delete();

    expect(DB::table('passkeys')->count())->toBe(0);
});

it('never serialises factor or credential columns', function () {
    $user = User::factory()->create(['google_id' => '123']);
    Totp::enable($user);
    addPasskey($user);

    $json = $user->fresh()->toJson();
    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'password', 'remember_token', 'google_id'] as $key) {
        expect(array_key_exists($key, $user->fresh()->toArray()))->toBeFalse();
        expect($json)->not->toContain('"'.$key.'"');
    }
});

it('stores the secret encrypted and verifies codes', function () {
    $user = User::factory()->create();
    $secret = Totp::enable($user);

    expect(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toBe($secret)->not->toContain($secret);
    expect(app(TwoFactorAuthenticationProvider::class)->verify($secret, Totp::code($secret, 1)))->toBeTrue();
    expect(Totp::recoveryCodes($user))->toHaveCount(8);
});

it('defaults password_set to true and has a googleOnly factory state', function () {
    expect(User::factory()->create()->fresh()->password_set)->toBeTrue();

    $g = User::factory()->googleOnly()->create()->fresh();
    expect($g->password_set)->toBeFalse()->and($g->getAttributes()['google_id'])->not->toBeNull();
});

it('creates app_settings', function () {
    expect(Schema::hasColumns('app_settings', ['key', 'value']))->toBeTrue();
});

it('backfills password_set false only for google accounts with no known password', function () {
    $plain = User::factory()->create();
    $noAudit = User::factory()->create(['google_id' => '1']);
    $linkedActor = User::factory()->create(['google_id' => '2']);
    $linkedTarget = User::factory()->create(['google_id' => '3']);
    $reset = User::factory()->create(['google_id' => '4']);
    $resetTarget = User::factory()->create(['google_id' => '5']);
    auditRow('google_linked', actor: $linkedActor->id);
    auditRow('google_linked', target: $linkedTarget->id);
    auditRow('password_reset', actor: $reset->id);
    auditRow('password_reset', target: $resetTarget->id);

    expect(PasswordSetBackfill::run())->toBe(1);

    expect($noAudit->fresh()->password_set)->toBeFalse();
    foreach ([$plain, $linkedActor, $linkedTarget, $reset, $resetTarget] as $u) {
        expect($u->fresh()->password_set)->toBeTrue();
    }
    expect(PasswordSetBackfill::run())->toBe(0);
});

// 06-REVIEW WR-06
it('consumes a recovery code once even from a stale in-memory copy (the lock re-reads the stored codes)', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $code = Totp::recoveryCodes($user)[0];

    $first = User::find($user->id);
    $second = User::find($user->id); // loaded before the first use, still lists the code: a parallel request
    $first->replaceRecoveryCode($code);

    expect(fn () => $second->replaceRecoveryCode($code))->toThrow(\Illuminate\Http\Exceptions\HttpResponseException::class);
    expect(Totp::recoveryCodes($user->refresh()))->toHaveCount(7)->not->toContain($code);
});

it('rejects the second sequential use of a recovery code at the challenge', function () {
    $user = User::factory()->create();
    Totp::enable($user);
    $code = Totp::recoveryCodes($user)[0];

    foreach ([true, false] as $expectSuccess) {
        app('auth')->forgetGuards();
        $this->flushSession();
        $response = $this->withSession(['login.id' => $user->id, 'login.remember' => false, 'login.at' => now()->getTimestamp()])
            ->post('/two-factor-challenge', ['recovery_code' => $code]);
        $expectSuccess ? $response->assertRedirect('/') : $response->assertSessionHasErrors('recovery_code');
    }
    expect(Totp::recoveryCodes($user->refresh()))->toHaveCount(7);
});

it('keys the TOTP replay marker per member secret, not by the bare code', function () {
    $a = User::factory()->create();
    $secret = Totp::enable($a);
    $code = Totp::code($secret, -1);
    $provider = app(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class);

    expect($provider)->toBeInstanceOf(\App\Security\UserScopedTwoFactorProvider::class);
    expect($provider->verify($secret, $code))->toBeTrue();
    // Fortify's global key is untouched, so another member entering the same digits is not rejected by this use.
    expect(Cache::has('fortify.2fa_codes.'.md5($code)))->toBeFalse();
    expect(Cache::has(\App\Security\UserScopedTwoFactorProvider::replayKey($secret, $code)))->toBeTrue();
    // The same member cannot replay it.
    expect($provider->verify($secret, $code))->toBeFalse();
    // A different secret has its own marker.
    expect(\App\Security\UserScopedTwoFactorProvider::replayKey('OTHERSECRET', $code))->not->toBe(\App\Security\UserScopedTwoFactorProvider::replayKey($secret, $code));
});
