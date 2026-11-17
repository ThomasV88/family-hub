<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\ModulePermission;
use App\Models\User;
use App\Models\WishlistManager;
use App\Security\SensitiveSession;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create(['locale' => 'pl']);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->other = User::factory()->withPreset(Preset::Parent)->create();
});

it('creates a child with the Child grants, a manager and one audit row, without any e-mail', function () {
    Notification::fake();

    $this->actingAs($this->admin)->postJson('/settings/members/children', ['name' => '  Ema   Nova ', 'parent_ids' => [$this->parent->id]])
        ->assertRedirect('/settings/members')
        ->assertSessionHas('toast.key', 'settings.members.child.toast_added');

    $child = User::query()->where('name', 'Ema Nova')->sole();
    expect($child->email)->toBeNull()
        ->and($child->sign_in_disabled)->toBeTrue()
        ->and($child->password_set)->toBeFalse()
        ->and($child->canSignIn())->toBeFalse()
        ->and($child->locale)->toBe('pl')
        ->and($child->is_admin)->toBeFalse()
        ->and(Hash::check('', $child->password))->toBeFalse()
        ->and(session('flash_data.created_child_id'))->toBe($child->id);

    $invited = User::factory()->withPreset(Preset::Child)->create();
    $grants = fn (User $u) => ModulePermission::query()->where('user_id', $u->id)->orderBy('module')->get()
        ->mapWithKeys(fn ($p) => [$p->module->value => $p->level->value])->all();
    expect($grants($child))->toBe($grants($invited));

    expect(WishlistManager::query()->where('owner_id', $child->id)->pluck('manager_id')->all())->toBe([$this->parent->id]);

    $row = AuditEntry::query()->where('event', 'child_created')->sole();
    expect($row->actor_id)->toBe($this->admin->id)->and($row->target_user_id)->toBe($child->id)->and($row->meta)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses a stale admin and creates nothing', function () {
    $this->useRealSensitiveSession();
    $before = User::count();

    $this->actingAs($this->admin)->postJson('/settings/members/children', ['name' => 'Ema', 'parent_ids' => [$this->parent->id]])->assertStatus(423);
    expect(User::count())->toBe($before);

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin)->withSession([SensitiveSession::CONFIRMED_KEY => now()->getTimestamp()])
        ->postJson('/settings/members/children', ['name' => 'Ema', 'parent_ids' => [$this->parent->id]])->assertRedirect();
    expect(User::count())->toBe($before + 1);
});

it('keeps non-admins out', function () {
    $this->actingAs($this->parent)->postJson('/settings/members/children', ['name' => 'Ema', 'parent_ids' => [$this->parent->id]])->assertForbidden();
    expect(User::query()->where('name', 'Ema')->exists())->toBeFalse();
});

it('refuses invalid input and creates nothing', function (callable $payload) {
    $data = $payload($this);
    $before = User::count();

    $this->actingAs($this->admin)->postJson('/settings/members/children', $data)->assertStatus(422);

    expect(User::count())->toBe($before)->and(WishlistManager::count())->toBe(0);
})->with([
    'no parents' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => []]],
    'missing parents' => [fn ($t) => ['name' => 'Ema']],
    'no name' => [fn ($t) => ['name' => '  ', 'parent_ids' => [$t->parent->id]]],
    'control character' => [fn ($t) => ['name' => "Em\x07a", 'parent_ids' => [$t->parent->id]]],
    'too long' => [fn ($t) => ['name' => str_repeat('a', 61), 'parent_ids' => [$t->parent->id]]],
    'unknown parent' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => [999999]]],
    'duplicate parent' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => [$t->parent->id, $t->parent->id]]],
    'admin without wishlist edit' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => [$t->admin->id]]],
    'view-only member' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => [User::factory()->withPreset(Preset::Guest)->create()->id]]],
    'a child as parent' => [fn ($t) => ['name' => 'Ema', 'parent_ids' => [User::factory()->child()->withPreset(Preset::Child)->create()->id]]],
]);

it('answers parent list shape errors with translation keys, never a raw validation key', function (callable $parents) {
    $response = $this->actingAs($this->admin)->postJson('/settings/members/children', ['name' => 'Ema', 'parent_ids' => $parents($this)])->assertStatus(422);

    foreach (collect($response->json('errors'))->flatten() as $message) {
        expect($message)->toStartWith('settings.members.child.')->and($message)->not->toContain('validation.');
    }
})->with([
    'not an integer' => [fn ($t) => ['abc']],
    'duplicate' => [fn ($t) => [$t->parent->id, $t->parent->id]],
]);

it('refuses a child or an ineligible member as manager on a later change', function (callable $make) {
    $child = User::factory()->child()->withPreset(Preset::Child)->create();
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => $this->parent->id]);

    $this->actingAs($this->admin)->putJson("/settings/members/{$child->id}/wishlist-managers", ['manager_ids' => [$make()->id]])->assertStatus(422);

    expect(WishlistManager::query()->where('owner_id', $child->id)->pluck('manager_id')->all())->toBe([$this->parent->id]);
})->with([
    'a child' => [fn () => User::factory()->child()->withPreset(Preset::Child)->create()],
    'view-only' => [fn () => User::factory()->withPreset(Preset::Guest)->create()],
]);

it('accepts two eligible parents and keeps one row each', function () {
    $this->actingAs($this->admin)->postJson('/settings/members/children', ['name' => 'Ema', 'parent_ids' => [$this->parent->id, $this->other->id]])->assertRedirect();

    expect(WishlistManager::query()->pluck('manager_id')->sort()->values()->all())->toBe(collect([$this->parent->id, $this->other->id])->sort()->values()->all());
});
