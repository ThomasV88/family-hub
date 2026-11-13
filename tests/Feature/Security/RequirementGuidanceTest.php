<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Security\SecondFactorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Fortify;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('x');
    // Real windows everywhere: this file proves the loop through the real gate.
    $this->useRealSensitiveSession();
});

/** Acts as $user in a clean browser session; $minutesOld is the age of the last identity proof (null = just now). */
function actAs(User $user, ?int $minutesOld = null, bool $active = true): void
{
    app('auth')->forgetGuards();
    test()->flushSession();
    $confirmed = $minutesOld === null ? now()->getTimestamp() : now()->subMinutes($minutesOld)->getTimestamp();
    $session = ['auth.password_confirmed_at' => $confirmed];
    if ($active) {
        $session['sensitive.active_at'] = now()->getTimestamp();
    }
    test()->actingAs($user)->withSession($session);
}

function financeMember(string $name = 'Member'): User
{
    return User::factory()->withPreset(Preset::Parent)->create(['name' => $name])->refresh();
}

function requireSecondFactor(): void
{
    $admin = User::factory()->admin()->create();
    actAs($admin);
    test()->putJson('/settings/security/policy', ['required' => true])->assertOk();
}

it('does not bother anyone while the requirement is off', function () {
    $member = financeMember();
    actAs($member);

    $this->get('/finance')->assertOk();
});

it('guides a finance member to Settings > Security once the admin switches the requirement on', function () {
    $member = financeMember();
    $admin = User::factory()->admin()->create();

    actAs($admin);
    $this->put('/settings/security/policy', ['required' => true])->assertStatus(303);

    actAs($member);
    $this->get('/finance')->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'second_factor_required')
        ->assertSessionHas('url.intended', '/finance');

    actAs($member);
    $this->getJson('/finance')->assertStatus(423)->assertJson(['error' => 'second_factor_required']);

    actAs($member);
    $this->get('/')->assertOk();
    $this->get('/todo')->assertOk();
    $this->get('/settings/security')->assertOk();
});

it('releases the member once the authenticator is confirmed, without a confirm-access loop', function () {
    $member = financeMember();
    requireSecondFactor();

    actAs($member);
    $this->get('/finance')->assertRedirect('/settings/security');
    $this->postJson('/user/two-factor-authentication')->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($member->fresh()->two_factor_secret);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])->assertOk();

    $this->post('/settings/security/continue')->assertStatus(303)->assertRedirect('/finance');
    $this->get('/finance')->assertOk();
});

it('lets a stale session confirm first, then enrol and continue', function () {
    $member = financeMember();
    requireSecondFactor();

    // Stale proof (as after a remember-me restore): no windows at all except an 11 minute old confirmation.
    actAs($member, 11, active: false);
    $this->get('/finance')->assertRedirect('/settings/security')->assertSessionHas('url.intended', '/finance');

    $this->postJson('/user/two-factor-authentication')->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    $this->postJson('/confirm-access', ['password' => 'password'])->assertOk()->assertJson(['confirmed' => true]);

    $this->postJson('/user/two-factor-authentication')->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($member->fresh()->two_factor_secret);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])->assertOk();

    $this->post('/settings/security/continue')->assertStatus(303)->assertRedirect('/finance');
    $this->get('/finance')->assertOk();
});

it('does not let a stolen stale session enrol or escape the guidance', function () {
    $member = financeMember();
    requireSecondFactor();
    $secret = Totp::enable($member, confirm: false);

    actAs($member, 11, active: false);
    $this->postJson('/user/two-factor-authentication')->assertStatus(423);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => Totp::code($secret)])->assertStatus(423);
    $this->get('/finance')->assertRedirect('/settings/security');

    expect($member->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('puts the guidance ahead of the confirm page for a stale member', function () {
    $member = financeMember();
    requireSecondFactor();

    actAs($member, 11, active: false);
    $this->get('/finance')->assertRedirect('/settings/security')->assertRedirectContains('/settings/security');
});

it('never redirects to-do-only members or an admin without module grants', function () {
    requireSecondFactor();
    $todoOnly = User::factory()->withPreset(Preset::Child)->create();
    $admin = User::factory()->admin()->create();

    actAs($todoOnly);
    $this->get('/todo')->assertOk();
    $this->get('/finance')->assertForbidden();

    actAs($admin);
    $this->get('/settings/members')->assertOk();
    $this->get('/finance')->assertForbidden();
    expect(app(SecondFactorPolicy::class)->mustEnrol($todoOnly))->toBeFalse()
        ->and(app(SecondFactorPolicy::class)->mustEnrol($admin))->toBeFalse();
});

it('guides a member again after an admin reset and sign-in', function () {
    $admin = User::factory()->admin()->create();
    $member = financeMember();
    requireSecondFactor();
    Totp::enable($member);
    DB::table('sessions')->insert(['id' => str_repeat('c', 40), 'user_id' => $member->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

    actAs($member);
    $this->get('/finance')->assertOk();

    actAs($admin);
    $this->deleteJson("/settings/members/{$member->id}/second-factor")->assertOk();
    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0)
        ->and(AuditEntry::where('event', 'second_factor_reset')->count())->toBe(1);

    // The old session is gone (the array test driver cannot drop it, so model the revoked browser): login required.
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->get('/finance')->assertRedirect('/login');

    // Signing in again (no factor any more) lands on the guidance again.
    $this->post('/login', ['email' => $member->email, 'password' => 'password']);
    $this->get('/finance')->assertRedirect('/settings/security');
});
