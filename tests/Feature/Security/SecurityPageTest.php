<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Security\SecondFactorPolicy;
use App\Support\SettingsSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

function securityPasskey(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'secret-cred-id-'.$user->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('sends guests to login', function () {
    $this->get('/settings/security')->assertRedirect('/login');
    $this->post('/settings/security/continue')->assertRedirect('/login');
});

it('renders the page for a plain member with the full props shape and no-store', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/security');
    $response->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Security')
        ->has('sections')
        ->where('status.totp', false)
        ->where('status.recoveryRemaining', null)
        ->where('passkeys', [])
        ->where('required', false)
        ->where('guidance', false)
        ->where('continueTo', null)
        ->has('methods')
        ->has('confirmFresh')
        ->has('passkeysEnabled'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('counts recovery codes and drops one after use', function () {
    $user = User::factory()->create();
    Totp::enable($user);

    $this->actingAs($user)->get('/settings/security')->assertInertia(fn (Assert $p) => $p
        ->where('status.totp', true)->where('status.recoveryRemaining', 8));

    $user->replaceRecoveryCode($user->recoveryCodes()[0]);

    $this->actingAs($user->fresh())->get('/settings/security')->assertInertia(fn (Assert $p) => $p
        ->where('status.recoveryRemaining', 7));
});

it('maps passkeys to display fields only', function () {
    $user = User::factory()->create();
    securityPasskey($user);

    $this->actingAs($user)->get('/settings/security')->assertInertia(fn (Assert $p) => $p
        ->has('passkeys', 1)
        ->has('passkeys.0', fn (Assert $k) => $k->hasAll(['id', 'name', 'authenticator', 'createdAt', 'lastUsedAt'])->etc())
        ->where('passkeys.0.name', 'Laptop'));

    $keys = array_keys($this->actingAs($user)->get('/settings/security')->viewData('page')['props']['passkeys'][0]);
    expect($keys)->toBe(['id', 'name', 'authenticator', 'createdAt', 'lastUsedAt']);
});

it('never puts a secret, recovery code or credential id in the response', function () {
    $user = User::factory()->create();
    $secret = Totp::enable($user);
    securityPasskey($user);
    $codes = Totp::recoveryCodes($user);

    $json = $this->actingAs($user)->get('/settings/security')->viewData('page');
    $blob = json_encode($json);

    expect($blob)->not->toContain($secret)
        ->not->toContain('two_factor_')
        ->not->toContain('credential_id')
        ->not->toContain('secret-cred-id');
    foreach ($codes as $code) {
        expect($blob)->not->toContain($code);
    }
});

it('guides a finance member without a factor under the requirement and continues to the intended page', function () {
    app(SecondFactorPolicy::class)->setRequired(true);
    $user = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    $this->actingAs($user)->withSession(['status' => 'second_factor_required', 'url.intended' => '/finance'])
        ->get('/settings/security')->assertInertia(fn (Assert $p) => $p
        ->where('guidance', true)->where('required', true)->where('continueTo', '/finance'));

    $this->actingAs($user)->withSession(['url.intended' => '/finance'])->post('/settings/security/continue')
        ->assertStatus(303)->assertRedirect('/finance')->assertSessionHas('toast', ['key' => 'security.guidance.done']);
});

it('continues to the home page when nothing was intended', function () {
    $this->actingAs(User::factory()->create())->post('/settings/security/continue')
        ->assertStatus(303)->assertRedirect('/');
});

it('never exposes an unsafe intended address', function () {
    app(SecondFactorPolicy::class)->setRequired(true);
    $user = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    $this->actingAs($user)->withSession(['url.intended' => 'https://evil.example/x'])->post('/settings/security/continue')
        ->assertRedirect('/');
});

it('shows no guidance or intended address to a member outside the requirement', function () {
    app(SecondFactorPolicy::class)->setRequired(true);
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['url.intended' => '/finance'])->get('/settings/security')
        ->assertInertia(fn (Assert $p) => $p->where('required', false)->where('guidance', false)->where('continueTo', null));
});

it('lists the security section for members and admins before appearance', function () {
    expect(SettingsSections::for(User::factory()->create()))->toHaveCount(3);
    $admin = collect(SettingsSections::for(User::factory()->admin()->create()))->pluck('key')->all();
    expect(array_slice($admin, -3))->toBe(['notifications', 'security', 'appearance']);
});
