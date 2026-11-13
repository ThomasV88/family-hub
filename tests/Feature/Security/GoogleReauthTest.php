<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Security\SensitiveSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeGoogle;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeGoogle::configure();
    $this->useRealSensitiveSession();
});

function gMember(array $state = []): User
{
    return User::factory()->googleOnly()->withPreset(Preset::Parent)->create($state)->refresh();
}

function gFlag(User $user, ?int $age = 0): array
{
    return ['reauth.google' => ['user' => $user->id, 'at' => now()->getTimestamp() - $age]];
}

function gStale(): array
{
    return ['sensitive.active_at' => now()->getTimestamp() - 3600];
}

it('redirects an eligible member to Google when the flag is on and shows the method', function () {
    config(['security.google_reauth' => true]);
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);

    $this->actingAs($user)->withSession(gStale())->get('/confirm-access/google')
        ->assertRedirect('https://accounts.google.test/auth')->assertSessionHas('reauth.google.user', $user->id);
    $this->get('/confirm-access')->assertInertia(fn (Assert $p) => $p->where('methods.google', true));
});

it('confirms with the same Google account and audits it', function () {
    config(['security.google_reauth' => true]);
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);

    $this->actingAs($user)->withSession(gStale() + gFlag($user) + ['url.intended' => '/finance'])
        ->get('/auth/google/callback')->assertStatus(303)->assertRedirect('/finance');

    expect(app(SensitiveSession::class)->fresh())->toBeTrue();
    $rows = AuditEntry::where('event', 'reauth_confirmed')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'google']);
});

it('is unavailable with the flag off', function () {
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);

    $this->actingAs($user)->withSession(gStale())->get('/confirm-access/google')->assertNotFound();
    $this->get('/confirm-access')->assertInertia(fn (Assert $p) => $p
        ->where('methods', ['password' => false, 'totp' => false, 'passkey' => false, 'google' => false]));
});

it('does not honour a forged session flag when the flag is off', function () {
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);

    $this->actingAs($user)->withSession(gStale() + gFlag($user))->get('/auth/google/callback')
        ->assertRedirect('/confirm-access')->assertSessionHas('status', 'google_reauth_failed');
    expect(app(SensitiveSession::class)->fresh())->toBeFalse();
});

it('gives 404 and no button to members with a password or a factor', function () {
    config(['security.google_reauth' => true]);

    $withPassword = gMember(['password_set' => true]);
    $this->actingAs($withPassword)->get('/confirm-access/google')->assertNotFound();
    $this->get('/confirm-access')->assertInertia(fn (Assert $p) => $p->where('methods.google', false));

    $withTotp = gMember();
    Totp::enable($withTotp);
    $this->actingAs($withTotp)->get('/confirm-access/google')->assertNotFound();

    $withPasskey = gMember();
    DB::table('passkeys')->insert([
        'user_id' => $withPasskey->id, 'name' => 'Laptop', 'credential_id' => 'c-'.$withPasskey->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($withPasskey)->get('/confirm-access/google')->assertNotFound();
});

it('gives 404 to a member without a Google link', function () {
    foreach ([true, false] as $flag) {
        config(['security.google_reauth' => $flag]);
        $user = User::factory()->withPreset(Preset::Parent)->create(['password_set' => false])->refresh();
        $this->actingAs($user)->get('/confirm-access/google')->assertNotFound();
    }
});

it('fails when a factor is enrolled between the redirect and the callback', function () {
    config(['security.google_reauth' => true]);
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);
    $this->actingAs($user)->withSession(gStale())->get('/confirm-access/google')->assertRedirect();

    Totp::enable($user);
    $this->get('/auth/google/callback')->assertRedirect('/confirm-access')->assertSessionHas('status', 'google_reauth_failed');
    expect(app(SensitiveSession::class)->fresh())->toBeFalse();
});

it('rejects a different Google account without linking or creating anything', function () {
    config(['security.google_reauth' => true]);
    $user = gMember();
    FakeGoogle::returns('someone-else', 'other@example.test');
    $users = User::count();

    $this->actingAs($user)->withSession(gStale() + gFlag($user))->get('/auth/google/callback')
        ->assertRedirect('/confirm-access')->assertSessionHas('status', 'google_reauth_failed');

    expect(app(SensitiveSession::class)->fresh())->toBeFalse()
        ->and(User::count())->toBe($users)
        ->and(AuditEntry::where('event', 'google_linked')->count())->toBe(0);
    $rows = AuditEntry::where('event', 'second_factor_failed')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->meta)->toBe(['method' => 'google', 'context' => 'reauth']);
});

it('rejects an old flag and one bound to another member', function () {
    config(['security.google_reauth' => true]);
    $user = gMember();
    $other = gMember();
    FakeGoogle::returns($user->google_id, $user->email);

    $this->actingAs($user)->withSession(gStale() + gFlag($user, 700))->get('/auth/google/callback')
        ->assertRedirect('/confirm-access');
    $this->withSession(gStale() + gFlag($other))->get('/auth/google/callback')->assertRedirect('/confirm-access');
    expect(app(SensitiveSession::class)->fresh())->toBeFalse();
});

it('sends a signed-in member without a flag home', function () {
    $user = gMember();
    FakeGoogle::returns($user->google_id, $user->email);
    $this->actingAs($user)->get('/auth/google/callback')->assertRedirect('/');
});
