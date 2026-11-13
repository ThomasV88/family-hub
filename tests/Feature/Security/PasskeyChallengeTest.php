<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakePasskeys;

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
    RateLimiter::clear('x');
});

function pcMember(): User
{
    return User::factory()->create(['email' => 'anna@example.test']);
}

function pcPasskey(User $user, string $bytes = 'anna-key'): string
{
    $id = Base64UrlSafe::encodeUnpadded($bytes);
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => $id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Pending login after the password step (what RedirectIfSecondFactor writes). */
function pcPending(User $user, array $extra = []): array
{
    return $extra + ['login.id' => $user->id, 'login.remember' => false, 'login.at' => now()->getTimestamp()];
}

function pcRows(string $event)
{
    return AuditEntry::where('event', $event)->get();
}

it('offers options limited to the pending member\'s own passkeys', function () {
    $anna = pcMember();
    $mine = pcPasskey($anna);
    pcPasskey(User::factory()->create(), 'someone-else');

    $response = $this->withSession(pcPending($anna))->getJson('/two-factor-challenge/passkey/options')->assertOk();

    expect(array_column($response->json('options.allowCredentials'), 'id'))->toBe([$mine])
        ->and($response->json('options.userVerification'))->toBe('required');
    expect(session('passkey.verification_options'))->toBeString();
    $this->assertGuest();
});

it('finishes the sign-in with the member\'s passkey', function () {
    $anna = pcMember();
    $id = pcPasskey($anna);
    FakePasskeys::verifies();

    $this->withSession(pcPending($anna, ['url.intended' => 'http://localhost/finance']))->getJson('/two-factor-challenge/passkey/options');
    $oldSession = session()->getId();

    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])
        ->assertOk()->assertExactJson(['redirect' => '/finance']);

    $this->assertAuthenticatedAs($anna);
    expect(session()->getId())->not->toBe($oldSession)
        ->and(session()->has('login.id'))->toBeFalse()
        ->and(session()->has('login.remember'))->toBeFalse()
        ->and(session()->has('login.at'))->toBeFalse()
        ->and(session('auth.password_confirmed_at'))->toBeInt()
        ->and(session('sensitive.active_at'))->toBeInt()
        ->and(pcRows('login_success'))->toHaveCount(1)
        ->and(pcRows('second_factor_failed'))->toHaveCount(0);

    $this->get('/')->assertOk();
});

it('honours remember-me from the password step', function () {
    $anna = pcMember();
    $id = pcPasskey($anna);
    FakePasskeys::verifies();

    $this->withSession(pcPending($anna, ['login.remember' => true]))->getJson('/two-factor-challenge/passkey/options');
    $response = $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])->assertOk();

    $response->assertCookie(Auth::guard('web')->getRecallerName());
});

it('turns any WebAuthn failure into a generic failed check', function () {
    $anna = pcMember();
    $id = pcPasskey($anna);
    FakePasskeys::rejects(new RuntimeException('Invalid counter secret-detail'));

    $this->withSession(pcPending($anna))->getJson('/two-factor-challenge/passkey/options');
    $response = $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)]);

    $response->assertStatus(422)->assertExactJson(['error' => 'passkey_failed']);
    $this->assertGuest();
    $rows = pcRows('second_factor_failed');
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'passkey', 'context' => 'login'])
        ->and(pcRows('login_success'))->toHaveCount(0)
        ->and($response->getContent())->not->toContain('secret-detail');
});

it('refuses a credential that belongs to another member', function () {
    $anna = pcMember();
    pcPasskey($anna);
    $otherId = pcPasskey(User::factory()->create(), 'other-key');
    FakePasskeys::verifies();

    $this->withSession(pcPending($anna))->getJson('/two-factor-challenge/passkey/options');
    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($otherId)])
        ->assertStatus(422)->assertExactJson(['error' => 'passkey_failed']);

    $this->assertGuest();
});

it('fails without stored options (replay of a consumed challenge)', function () {
    $anna = pcMember();
    $id = pcPasskey($anna);
    FakePasskeys::verifies();

    $this->withSession(pcPending($anna))->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])
        ->assertStatus(422)->assertExactJson(['error' => 'passkey_failed']);
    $this->assertGuest();
});

it('answers a malformed payload with a validation error, never a 500', function () {
    $anna = pcMember();
    pcPasskey($anna);

    $this->withSession(pcPending($anna))->postJson('/two-factor-challenge/passkey', ['credential' => ['id' => 'x', 'rawId' => '!!', 'type' => 'public-key', 'response' => ['a' => 1]]])
        ->assertStatus(422);
    $this->postJson('/two-factor-challenge/passkey', ['credential' => 'nope'])->assertStatus(422);
    $this->assertGuest();
});

it('refuses without a pending login or without a passkey, never with a 500', function () {
    $this->get('/two-factor-challenge/passkey/options')->assertRedirect('/login');
    $this->getJson('/two-factor-challenge/passkey/options')->assertStatus(401)->assertExactJson(['error' => 'no_pending_login']);
    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion(Base64UrlSafe::encodeUnpadded('k'))])->assertStatus(401);

    $noPasskey = pcMember();
    $this->withSession(pcPending($noPasskey))->getJson('/two-factor-challenge/passkey/options')->assertStatus(401);
    $this->withSession(['login.id' => 'abc', 'login.at' => now()->getTimestamp()])->getJson('/two-factor-challenge/passkey/options')->assertStatus(401);
    $this->assertGuest();
});

it('sends a signed-in member home', function () {
    $user = pcMember();
    $this->actingAs($user)->get('/two-factor-challenge/passkey/options')->assertRedirect();
    $this->post('/two-factor-challenge/passkey', [])->assertRedirect();
});

it('answers an expired challenge with 410', function () {
    $anna = pcMember();
    pcPasskey($anna);

    $this->withSession(['login.id' => $anna->id, 'login.remember' => false, 'login.at' => now()->subMinutes(11)->getTimestamp()])
        ->getJson('/two-factor-challenge/passkey/options')
        ->assertStatus(410)->assertExactJson(['error' => 'challenge_expired']);
    expect(session()->has('login.id'))->toBeFalse();
});

it('throttles repeated failures per pending member', function () {
    $anna = pcMember();
    $id = pcPasskey($anna);
    FakePasskeys::rejects();

    $this->withSession(pcPending($anna));
    foreach (range(1, 5) as $_) {
        $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])->assertStatus(422);
    }

    $this->postJson('/two-factor-challenge/passkey', ['credential' => FakePasskeys::assertion($id)])
        ->assertStatus(429)->assertJsonPath('error', 'throttle');
    $this->assertGuest();
});
