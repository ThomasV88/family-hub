<?php

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

const SECURITY_EVENTS = [
    'two_factor_enabled', 'two_factor_disabled', 'recovery_codes_regenerated', 'recovery_code_used', 'passkey_added',
    'passkey_renamed', 'passkey_removed', 'second_factor_failed', 'reauth_confirmed', 'second_factor_reset', 'security_policy_changed',
];

function fakePasskey(User $user): Passkey
{
    $p = new Passkey(['name' => 'Secret laptop name', 'credential_id' => 'cred-abc', 'credential' => ['x' => 1]]);
    $p->user_id = $user->id;
    $p->save();

    return $p;
}

it('groups every security event under security', function () {
    expect(AuditEvent::GROUPS)->toContain('security')->and(array_last(AuditEvent::GROUPS))->toBe('security');
    foreach (SECURITY_EVENTS as $value) {
        expect(AuditEvent::from($value)->group())->toBe('security')->and(strlen($value))->toBeLessThanOrEqual(40);
    }
    expect(AuditEvent::eventsForGroup('security')['events'])->toEqualCanonicalizing(SECURITY_EVENTS);
});

it('allowlists meta keys', function () {
    expect(AuditEvent::SecondFactorFailed->metaKeys())->toBe(['method', 'context'])
        ->and(AuditEvent::ReauthConfirmed->metaKeys())->toBe(['method'])
        ->and(AuditEvent::SecurityPolicyChanged->metaKeys())->toBe(['setting', 'value']);

    foreach (SECURITY_EVENTS as $value) {
        $e = AuditEvent::from($value);
        if (in_array($e, [AuditEvent::SecondFactorFailed, AuditEvent::ReauthConfirmed, AuditEvent::SecurityPolicyChanged], true)) {
            continue;
        }
        expect($e->metaKeys())->toBe([]);
        expect(fn () => app(AuditLogger::class)->record($e, null, ['name' => 'Laptop', 'code' => '123456']))->toThrow(InvalidArgumentException::class);
    }
});

it('audits two-factor confirmation', function () {
    $user = User::factory()->create();
    TwoFactorAuthenticationConfirmed::dispatch($user);

    $row = AuditEntry::sole();
    expect($row->event)->toBe('two_factor_enabled')->and($row->actor_id)->toBe($user->id)->and($row->meta)->toBeNull();
});

it('audits recovery code regeneration only for a confirmed factor', function () {
    $pending = User::factory()->create();
    Totp::enable($pending, confirm: false);
    expect(AuditEntry::count())->toBe(0);

    $confirmed = User::factory()->create();
    Totp::enable($confirmed);
    $before = AuditEntry::max('id');
    RecoveryCodesGenerated::dispatch($confirmed->fresh());

    expect(AuditEntry::where('id', '>', $before)->sole()->event)->toBe('recovery_codes_regenerated');
});

it('audits recovery code use and failed challenges without content', function () {
    $user = User::factory()->create();
    RecoveryCodeReplaced::dispatch($user, 'AAAAA-BBBBB');
    $row = AuditEntry::latest('id')->first();
    expect($row->event)->toBe('recovery_code_used');
    expect(json_encode($row->getAttributes()))->not->toContain('AAAAA');

    TwoFactorAuthenticationFailed::dispatch($user);
    expect(AuditEntry::latest('id')->first()->meta)->toBe(['method' => 'totp', 'context' => 'login']);

    request()->merge(['recovery_code' => 'CCCCC-DDDDD']);
    TwoFactorAuthenticationFailed::dispatch($user);
    $row = AuditEntry::latest('id')->first();
    expect($row->meta)->toBe(['method' => 'recovery', 'context' => 'login']);
    expect(json_encode($row->getAttributes()))->not->toContain('CCCCC');
});

it('audits passkey add and remove without name or credential id', function () {
    $user = User::factory()->create();
    $passkey = fakePasskey($user);

    PasskeyRegistered::dispatch($user, $passkey);
    PasskeyDeleted::dispatch($user, $passkey);

    expect(AuditEntry::orderBy('id')->pluck('event')->all())->toBe(['passkey_added', 'passkey_removed']);
    foreach (AuditEntry::all() as $row) {
        expect($row->meta)->toBeNull();
        expect(json_encode($row->getAttributes()))->not->toContain('Secret laptop')->not->toContain('cred-abc');
    }
});

it('writes nothing for enable and disable events', function () {
    $user = User::factory()->create();
    TwoFactorAuthenticationEnabled::dispatch($user);
    TwoFactorAuthenticationDisabled::dispatch($user);

    expect(DB::table('audit_logs')->count())->toBe(0);
});
