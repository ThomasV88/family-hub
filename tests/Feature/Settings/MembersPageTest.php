<?php

use App\Access\Preset;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lists members sorted by name with access levels and the presets from the server', function () {
    $admin = User::factory()->admin()->create(['name' => 'Mira']);
    User::factory()->withPreset(Preset::Child)->create(['name' => 'Adam']);

    $this->actingAs($admin)->get('/settings/members')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Members')
        ->has('members', 2)
        ->where('members.0.name', 'Adam')
        ->where('members.0.isSelf', false)
        ->where('members.0.access', ['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'none', 'health' => 'none'])
        ->where('members.1.isSelf', true)
        ->where('members.1.isAdmin', true)
        ->where('members.1.access', ['todo' => 'none', 'wishlist' => 'none', 'finance' => 'none', 'health' => 'none'])
        ->has('members.0', fn (Assert $m) => $m->hasAll(['id', 'name', 'email', 'isAdmin', 'isSelf', 'access'])->etc())
        ->where('presets.guest', ['todo' => 'view', 'wishlist' => 'none', 'finance' => 'none', 'health' => 'none'])
        ->where('presets.parent', ['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'edit', 'health' => 'none'])
        ->has('presets', 4)
        ->where('openMember', null)
        ->has('sections', 9));
});

it('opens a member panel from the query and ignores unknown ids', function () {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->create();

    $this->actingAs($admin)->get('/settings/members?member='.$child->id)->assertInertia(fn (Assert $p) => $p->where('openMember', $child->id));
    $this->actingAs($admin)->get('/settings/members?member=99999')->assertInertia(fn (Assert $p) => $p->where('openMember', null));
});

it('does not leak secrets in the member payload', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['google_id' => 'g-secret-123']);

    $json = $this->actingAs($admin)->get('/settings/members', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])->getContent();

    expect($json)->not->toContain('g-secret-123')->not->toContain('remember_token')->not->toContain('"password"');
});

it('marks a child with its manager names and lists only eligible parents', function () {
    $admin = User::factory()->admin()->create(['name' => 'Mira']);
    $parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $guest = User::factory()->withPreset(Preset::Guest)->create(['name' => 'Gita']);
    $child = User::factory()->child()->withPreset(Preset::Child)->create(['name' => 'Ema']);
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => $parent->id]);

    $this->actingAs($admin)->get('/settings/members')->assertInertia(fn (Assert $p) => $p
        ->where('members', function ($members) use ($child, $parent) {
            $row = collect($members)->firstWhere('id', $child->id);
            $adult = collect($members)->firstWhere('id', $parent->id);

            return $row['signInDisabled'] === true && $row['isChild'] === true && $row['email'] === null
                && $row['managerNames'] === ['Petra'] && $row['photoVersion'] === null
                && $adult['isChild'] === false && $adult['signInDisabled'] === false && $adult['managerNames'] === [];
        })
        ->where('eligibleParents', [['id' => $parent->id, 'name' => 'Petra']]));
    expect($guest->id)->not->toBeNull();
});

it('exposes no photo path or secret on any member row', function () {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->child()->withPreset(Preset::Child)->create();
    $child->forceFill(['photo_path' => 'wish-images/profile-photos/secret-path-full.jpg', 'photo_thumb_path' => 'wish-images/profile-photos/secret-path-thumb.jpg', 'photo_version' => 3])->save();

    $props = $this->actingAs($admin)->get('/settings/members')->viewData('page')['props'];
    $json = json_encode($props['members']);

    expect($json)->not->toContain('secret-path')->not->toContain('photo_path')->not->toContain('photo_thumb_path')
        ->not->toContain('remember_token')->not->toContain('google_id')->not->toContain('"password"');
    $row = collect($props['members'])->firstWhere('id', $child->id);
    expect($row['photoVersion'])->toBe(3)->and(array_keys($row))->toEqualCanonicalizing([
        'id', 'name', 'email', 'signInDisabled', 'isChild', 'invitation', 'photoVersion', 'managerNames', 'isAdmin', 'isSelf',
        'access', 'wishlistManagerIds', 'security',
    ]);
});

it('forbids non-admins and redirects guests', function () {
    foreach ([Preset::Parent, Preset::Child, Preset::Grandparent, Preset::Guest] as $preset) {
        $this->actingAs(User::factory()->withPreset($preset)->create())->get('/settings/members')->assertForbidden();
    }
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->get('/settings/members')->assertRedirect('/login');
});

it('lists non-accepted invitations newest first with state and mail failure, without token data', function () {
    $admin = User::factory()->admin()->create();
    $make = fn (string $email, array $extra = []) => Invitation::create(array_merge([
        'email' => $email, 'token_hash' => hash('sha256', $email), 'preset' => 'child', 'locale' => 'sk',
        'expires_at' => now()->addDays(7),
    ], $extra));
    $make('old@example.test', ['expires_at' => now()->subDay()]);
    $make('failed@example.test', ['mail_failed_at' => now()]);
    $make('done@example.test', ['accepted_at' => now()]);

    $response = $this->actingAs($admin)->get('/settings/members');
    $response->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 2)
        ->where('invitations.0.email', 'failed@example.test')
        ->where('invitations.0.state', 'valid')
        ->where('invitations.0.mailFailed', true)
        ->where('invitations.0.preset', 'child')
        ->where('invitations.1.state', 'expired')
        ->where('invitations.1.mailFailed', false)
        ->has('invitations.0', fn (Assert $i) => $i->hasAll(['id', 'email', 'preset', 'locale', 'state', 'expiresAt', 'mailFailed'])));

    $json = $this->actingAs($admin)->get('/settings/members', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])->getContent();
    expect($json)->not->toContain('token_hash')->not->toContain(hash('sha256', 'old@example.test'));
});
