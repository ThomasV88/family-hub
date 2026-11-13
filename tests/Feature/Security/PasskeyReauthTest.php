<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakePasskeys;

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function prMember(bool $passkey = true): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create()->refresh();
    $id = Base64UrlSafe::encodeUnpadded('key-'.$user->id);
    if ($passkey) {
        DB::table('passkeys')->insert([
            'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => $id,
            'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return [$user, $id];
}

function prStale(array $extra = []): array
{
    return ['auth.password_confirmed_at' => now()->subMinutes(30)->getTimestamp(), 'sensitive.active_at' => now()->subMinutes(30)->getTimestamp()] + $extra;
}

function prRows(string $event)
{
    return AuditEntry::where('event', $event)->get();
}

it('confirms on the page, returns the intended path and opens both windows', function () {
    [$user, $id] = prMember();
    FakePasskeys::verifies();

    $this->actingAs($user)->withSession(prStale(['url.intended' => 'http://localhost/finance/transactions']))
        ->getJson('/confirm-access/passkey/options')->assertOk()->assertJsonStructure(['options' => ['challenge', 'allowCredentials']]);

    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($id), 'mode' => 'page'])
        ->assertOk()->assertExactJson(['redirect' => '/finance/transactions']);

    $this->getJson('/finance')->assertOk();
    $rows = prRows('reauth_confirmed');
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'passkey']);
});

it('confirms in the dialog without touching the stored return path', function () {
    [$user, $id] = prMember();
    FakePasskeys::verifies();

    $this->actingAs($user)->withSession(prStale(['url.intended' => '/finance/budgets']))->getJson('/confirm-access/passkey/options');
    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($id), 'mode' => 'dialog'])
        ->assertOk()->assertExactJson(['confirmed' => true]);

    expect(session('url.intended'))->toBe('/finance/budgets')
        ->and(session('auth.password_confirmed_at'))->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('fails generically and audits the failure', function () {
    [$user, $id] = prMember();
    FakePasskeys::rejects(new TypeError('secret-detail'));

    $this->actingAs($user)->withSession(prStale())->getJson('/confirm-access/passkey/options');
    $response = $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($id), 'mode' => 'page']);

    $response->assertStatus(422)->assertExactJson(['error' => 'passkey_failed']);
    expect($response->getContent())->not->toContain('secret-detail');
    $rows = prRows('second_factor_failed');
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'passkey', 'context' => 'reauth'])
        ->and(prRows('reauth_confirmed'))->toHaveCount(0);
    $this->getJson('/finance')->assertStatus(423);
});

it('refuses another member\'s credential', function () {
    [$user] = prMember();
    [, $otherId] = prMember();
    FakePasskeys::verifies();

    $this->actingAs($user)->withSession(prStale())->getJson('/confirm-access/passkey/options');
    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($otherId), 'mode' => 'dialog'])->assertStatus(422);
    expect(prRows('reauth_confirmed'))->toHaveCount(0);
});

it('has nothing to offer a member without passkeys', function () {
    [$user, $id] = prMember(passkey: false);
    FakePasskeys::verifies();

    $this->actingAs($user)->withSession(prStale())->getJson('/confirm-access/passkey/options')->assertNotFound();
    $this->postJson('/confirm-access/passkey', ['credential' => FakePasskeys::assertion($id)])->assertNotFound();
});

it('requires a signed-in member', function () {
    $this->getJson('/confirm-access/passkey/options')->assertStatus(401);
    $this->postJson('/confirm-access/passkey', [])->assertStatus(401);
});
