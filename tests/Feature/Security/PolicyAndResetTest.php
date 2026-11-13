<?php

use App\Access\Preset;
use App\Auth\SessionRevoker;
use App\Models\AuditEntry;
use App\Models\User;
use App\Security\SecondFactorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

function addPasskey(User $user, string $id): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Phone', 'credential_id' => $id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function addSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
}

function staleAdminWindow(): array
{
    return ['auth.password_confirmed_at' => now()->subMinutes(11)->getTimestamp()];
}

function auditRows(string $event): int
{
    return AuditEntry::where('event', $event)->count();
}

// --- policy -----------------------------------------------------------------------------------

it('turns the requirement on, audited', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/settings/security/policy', ['required' => true])->assertStatus(303);

    expect(app(SecondFactorPolicy::class)->required())->toBeTrue();
    $row = AuditEntry::where('event', 'security_policy_changed')->sole();
    expect($row->meta)->toEqual(['setting' => 'require_second_factor', 'value' => true]);
});

it('rejects a non-boolean value', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson('/settings/security/policy', ['required' => 'yes'])->assertStatus(422);
    $this->actingAs($admin)->putJson('/settings/security/policy', [])->assertStatus(422);
    expect(auditRows('security_policy_changed'))->toBe(0);
});

it('forbids non-admins and redirects guests on the policy', function () {
    $this->actingAs(User::factory()->withPreset(Preset::Parent)->create())->putJson('/settings/security/policy', ['required' => true])->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->put('/settings/security/policy', ['required' => true])->assertRedirect('/login');
});

it('refuses both directions on a stale admin window and saves nothing', function () {
    $this->useRealSensitiveSession();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->withSession(staleAdminWindow())->putJson('/settings/security/policy', ['required' => true])
        ->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    expect(app(SecondFactorPolicy::class)->required())->toBeFalse();

    app(SecondFactorPolicy::class)->setRequired(true);
    $this->actingAs($admin)->withSession(staleAdminWindow())->putJson('/settings/security/policy', ['required' => false])
        ->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    expect(DB::table('app_settings')->where('key', 'require_second_factor')->value('value'))->toBe('true');
    expect(auditRows('security_policy_changed'))->toBe(0);
});

it('saves on a fresh admin window', function () {
    $this->useRealSensitiveSession();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->putJson('/settings/security/policy', ['required' => true])->assertOk()->assertJson(['required' => true]);
    expect(auditRows('security_policy_changed'))->toBe(1);
});

// --- members props ----------------------------------------------------------------------------

it('exposes per-member factor status without secrets', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    $totp = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Beta']);
    Totp::enable($totp);
    $passkey = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Cecil']);
    addPasskey($passkey, 'cred-secret-abc');
    $bare = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Dora']);
    $child = User::factory()->withPreset(Preset::Child)->create(['name' => 'Eva']);

    $get = fn () => $this->actingAs($admin)->get('/settings/members');
    $byName = fn (Assert $p, string $name) => $p;

    $get()->assertInertia(function (Assert $page) {
        $page->where('securityPolicy', ['required' => false, 'pending' => 1])
            ->where('confirmFresh', true)
            ->has('methods')
            ->where('members.0.security', ['totp' => false, 'passkeys' => 0, 'required' => false, 'needs' => false]) // Admin
            ->where('members.1.security', ['totp' => true, 'passkeys' => 0, 'required' => false, 'needs' => false])
            ->where('members.2.security', ['totp' => false, 'passkeys' => 1, 'required' => false, 'needs' => false])
            ->where('members.3.security.needs', false);
    });

    app(SecondFactorPolicy::class)->setRequired(true);
    $this->app->forgetInstance(SecondFactorPolicy::class);

    $response = $get();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('securityPolicy', ['required' => true, 'pending' => 1])
        ->where('members.3.security', ['totp' => false, 'passkeys' => 0, 'required' => true, 'needs' => true])
        ->where('members.4.security', ['totp' => false, 'passkeys' => 0, 'required' => false, 'needs' => false]));

    $json = $this->actingAs($admin)->get('/settings/members', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])->getContent();
    expect($json)->not->toContain('two_factor_')->not->toContain('credential_id')->not->toContain('cred-secret-abc');
    unset($bare, $child);
});

// --- reset ------------------------------------------------------------------------------------

it('resets all factors, revokes sessions and audits with actor and target', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->withPreset(Preset::Parent)->create();
    Totp::enable($member);
    addPasskey($member, 'a');
    addPasskey($member, 'b');
    addSession($member, str_repeat('a', 40));
    addSession($member, str_repeat('b', 40));
    $token = $member->fresh()->remember_token;

    $this->actingAs($admin)->deleteJson("/settings/members/{$member->id}/second-factor")->assertOk()->assertJson(['reset' => true]);

    $member->refresh();
    expect($member->two_factor_secret)->toBeNull()
        ->and($member->two_factor_recovery_codes)->toBeNull()
        ->and($member->two_factor_confirmed_at)->toBeNull()
        ->and($member->remember_token)->not->toBe($token)
        ->and(DB::table('passkeys')->where('user_id', $member->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    $row = AuditEntry::where('event', 'second_factor_reset')->sole();
    expect($row->actor_id)->toBe($admin->id)->and($row->target_user_id)->toBe($member->id)->and($row->meta)->toBeNull();
});

it('redirects back with a toast for a non-JSON reset', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    addPasskey($member, 'a');

    $this->actingAs($admin)->from('/settings/members')->delete("/settings/members/{$member->id}/second-factor")
        ->assertStatus(303)->assertRedirect('/settings/members')
        ->assertSessionHas('toast.key', 'members.security.reset_toast');
});

it('refuses a reset on a stale admin window without changing anything', function () {
    $this->useRealSensitiveSession();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    Totp::enable($member);

    $this->actingAs($admin)->withSession(staleAdminWindow())->deleteJson("/settings/members/{$member->id}/second-factor")
        ->assertStatus(423)->assertJson(['error' => 'confirm_required']);

    expect($member->fresh()->two_factor_secret)->not->toBeNull();
    expect(auditRows('second_factor_reset'))->toBe(0);
});

it('refuses the own row, a member without a factor and a non-numeric id', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    Totp::enable($admin);

    $this->actingAs($admin)->deleteJson("/settings/members/{$admin->id}/second-factor")->assertForbidden();
    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
    $this->actingAs($admin)->deleteJson("/settings/members/{$member->id}/second-factor")->assertStatus(422)->assertJson(['error' => 'no_factor']);
    $this->actingAs($admin)->deleteJson('/settings/members/abc/second-factor')->assertNotFound();
});

it('rolls everything back when the session revoke fails', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    Totp::enable($member);
    addPasskey($member, 'a');

    $this->app->instance(SessionRevoker::class, new class extends SessionRevoker
    {
        public function revokeAll(User $user): int
        {
            throw new RuntimeException('boom');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($admin)->deleteJson("/settings/members/{$member->id}/second-factor"))->toThrow(RuntimeException::class);

    expect($member->fresh()->two_factor_secret)->not->toBeNull()
        ->and(DB::table('passkeys')->where('user_id', $member->id)->count())->toBe(1);
    expect(auditRows('second_factor_reset'))->toBe(0);
});
