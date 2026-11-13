<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakePasskeys;

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function pkMember(): User
{
    return User::factory()->withPreset(Preset::Parent)->create()->refresh();
}

function pkRow(User $user, string $name = 'Laptop'): int
{
    return DB::table('passkeys')->insertGetId([
        'user_id' => $user->id, 'name' => $name, 'credential_id' => Base64UrlSafe::encodeUnpadded('c-'.$name.$user->id),
        'credential' => json_encode(['aaguid' => '00000000-0000-0000-0000-000000000000']), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pkAudit(string $event)
{
    return AuditEntry::where('event', $event)->get();
}

function pkFresh(): array
{
    return ['auth.password_confirmed_at' => now()->getTimestamp()];
}

function pkStale(): array
{
    return ['auth.password_confirmed_at' => now()->subMinutes(11)->getTimestamp()];
}

/** Runs the options step, then posts a registration for the same session. */
function pkRegister(object $test, User $user, array $session, string $name = 'iPhone')
{
    Illuminate\Support\Facades\Cache::flush(); // the throttle is not under test here
    FakePasskeys::registers();
    $test->actingAs($user)->withSession($session)->getJson('/settings/security/passkeys/options');

    return $test->postJson('/settings/security/passkeys', ['name' => $name, 'credential' => FakePasskeys::attestation()]);
}

it('hands out user-verification-required creation options and stores them in the session', function () {
    $user = pkMember();

    $this->actingAs($user)->withSession(pkFresh())->getJson('/settings/security/passkeys/options')
        ->assertOk()
        ->assertJsonStructure(['options' => ['challenge', 'rp', 'user', 'excludeCredentials', 'authenticatorSelection']])
        ->assertJsonPath('options.authenticatorSelection.userVerification', 'required')
        ->assertHeader('Cache-Control');

    expect(session('passkey.registration_options'))->toBeString();
});

it('refuses options and store on a stale session: no options stored, no passkey, no audit row', function () {
    $user = pkMember();

    $this->actingAs($user)->withSession(pkStale())->getJson('/settings/security/passkeys/options')
        ->assertStatus(423)->assertExactJson(['error' => 'confirm_required']);
    expect(session()->has('passkey.registration_options'))->toBeFalse();

    FakePasskeys::registers();
    $this->postJson('/settings/security/passkeys', ['name' => 'iPhone', 'credential' => FakePasskeys::attestation()])
        ->assertStatus(423)->assertExactJson(['error' => 'confirm_required']);

    expect(DB::table('passkeys')->count())->toBe(0)->and(pkAudit('passkey_added'))->toHaveCount(0);
});

it('registers a passkey right after sign-in and answers with display fields only', function () {
    $user = pkMember();

    $response = pkRegister($this, $user, pkFresh())->assertCreated();

    $response->assertJsonStructure(['passkey' => ['id', 'name', 'authenticator', 'createdAt', 'lastUsedAt']]);
    expect(array_keys($response->json('passkey')))->toBe(['id', 'name', 'authenticator', 'createdAt', 'lastUsedAt'])
        ->and($response->getContent())->not->toContain('credential');
    expect(DB::table('passkeys')->where('user_id', $user->id)->count())->toBe(1);

    $rows = pkAudit('passkey_added');
    expect($rows)->toHaveCount(1)->and(json_encode($rows[0]->meta))->not->toContain('iPhone');
});

it('answers a failed registration with a generic error and stores nothing', function () {
    $user = pkMember();
    FakePasskeys::registrationFails();
    $this->actingAs($user)->withSession(pkFresh())->getJson('/settings/security/passkeys/options');

    $this->postJson('/settings/security/passkeys', ['name' => 'iPhone', 'credential' => FakePasskeys::attestation()])
        ->assertStatus(422)->assertExactJson(['error' => 'passkey_failed']);
    expect(DB::table('passkeys')->count())->toBe(0);
});

it('validates the name: trimmed, 1 to 60 characters, no control characters', function () {
    $user = pkMember();

    pkRegister($this, $user, pkFresh(), '  iPhone  ')->assertCreated()->assertJsonPath('passkey.name', 'iPhone');

    foreach ([str_repeat('a', 61), "bad\x07name", "line\nbreak", "zero\u{200B}width"] as $bad) {
        pkRegister($this, $user, pkFresh(), $bad)->assertStatus(422)->assertJsonValidationErrors(['name' => 'passkey.error.name']);
    }
    pkRegister($this, $user, pkFresh(), '')->assertStatus(422)->assertJsonValidationErrors('name');
    pkRegister($this, $user, pkFresh(), str_repeat('a', 60))->assertCreated();

    expect(DB::table('passkeys')->count())->toBe(2);
});

it('renames an own passkey even on a stale session and audits it without the name', function () {
    $user = pkMember();
    $id = pkRow($user);

    $this->actingAs($user)->withSession(pkStale())->patchJson("/settings/security/passkeys/{$id}", ['name' => ' Work phone '])
        ->assertOk()->assertJsonPath('passkey.name', 'Work phone');

    expect(DB::table('passkeys')->where('id', $id)->value('name'))->toBe('Work phone');
    $rows = pkAudit('passkey_renamed');
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBeEmpty();

    $this->patchJson("/settings/security/passkeys/{$id}", ['name' => str_repeat('a', 61)])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'passkey.error.name']);
});

it('treats another member\'s passkey as missing', function () {
    $user = pkMember();
    $other = pkMember();
    $theirs = pkRow($other, 'Theirs');

    $this->actingAs($user)->withSession(pkFresh())->patchJson("/settings/security/passkeys/{$theirs}", ['name' => 'Mine now'])->assertNotFound();
    $this->deleteJson("/settings/security/passkeys/{$theirs}")->assertNotFound();

    expect(DB::table('passkeys')->where('id', $theirs)->value('name'))->toBe('Theirs');
    expect(pkAudit('passkey_removed'))->toHaveCount(0)->and(pkAudit('passkey_renamed'))->toHaveCount(0);
});

it('removes an own passkey only within the confirmation window', function () {
    $user = pkMember();
    $id = pkRow($user);

    $this->actingAs($user)->withSession(pkStale())->deleteJson("/settings/security/passkeys/{$id}")
        ->assertStatus(423)->assertExactJson(['error' => 'confirm_required']);
    expect(DB::table('passkeys')->where('id', $id)->exists())->toBeTrue()->and(pkAudit('passkey_removed'))->toHaveCount(0);

    $this->withSession(pkFresh())->deleteJson("/settings/security/passkeys/{$id}")->assertNoContent();
    expect(DB::table('passkeys')->where('id', $id)->exists())->toBeFalse()->and(pkAudit('passkey_removed'))->toHaveCount(1);
});

it('answers 404 on every passkey route when the flag is off', function () {
    config(['security.passkeys_enabled' => false]);
    $user = pkMember();
    $id = pkRow($user);
    $this->actingAs($user)->withSession(pkFresh());

    $this->getJson('/settings/security/passkeys/options')->assertNotFound();
    $this->postJson('/settings/security/passkeys', ['name' => 'x'])->assertNotFound();
    $this->patchJson("/settings/security/passkeys/{$id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/settings/security/passkeys/{$id}")->assertNotFound();
    $this->getJson('/confirm-access/passkey/options')->assertNotFound();
    $this->postJson('/confirm-access/passkey', [])->assertNotFound();
    $this->getJson('/two-factor-challenge/passkey/options')->assertNotFound();
    $this->postJson('/two-factor-challenge/passkey', [])->assertNotFound();

    expect(DB::table('passkeys')->where('id', $id)->value('name'))->toBe('Laptop');
});

it('keeps the user handle secret falling back to the app key and derives rp id and origin from APP_URL', function () {
    $fortify = require config_path('fortify.php');
    expect($fortify['passkeys'])->toBe([]);

    config(['app.url' => 'https://stage.example.test', 'fortify.passkeys' => [], 'app.key' => 'base64:abc']);
    app()->getProvider(FortifyServiceProvider::class); // sanity: the app provider is loaded
    app()->getProvider(\Laravel\Fortify\FortifyServiceProvider::class)->register();

    expect(config('passkeys.relying_party_id'))->toBe('stage.example.test')
        ->and(config('passkeys.allowed_origins'))->toBe(['https://stage.example.test'])
        ->and(config('passkeys.user_handle_secret'))->toBe('base64:abc');

    putenv('PASSKEYS_USER_HANDLE_SECRET=handle-secret');
    putenv('PASSKEYS_RP_ID=rp.example.test');
    putenv('PASSKEYS_ALLOWED_ORIGINS=https://a.example.test, https://b.example.test');
    try {
        $fortify = require config_path('fortify.php');
    } finally {
        putenv('PASSKEYS_USER_HANDLE_SECRET');
        putenv('PASSKEYS_RP_ID');
        putenv('PASSKEYS_ALLOWED_ORIGINS');
    }
    expect($fortify['passkeys'])->toBe([
        'relying_party_id' => 'rp.example.test',
        'allowed_origins' => ['https://a.example.test', 'https://b.example.test'],
        'user_handle_secret' => 'handle-secret',
    ]);
});

it('never enables Fortify\'s passkey feature or a passwordless login route', function () {
    expect(\Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::passkeys()))->toBeFalse();
    foreach (['passkeys/login', 'passkey/login', 'passkeys/login/options'] as $uri) {
        $this->postJson('/'.$uri)->assertNotFound();
    }
});
