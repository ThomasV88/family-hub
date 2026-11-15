<?php

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\ModulePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function payload(array $over = []): array
{
    return array_merge(['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'none', 'health' => 'none'], $over);
}

function auditRows(): array
{
    return DB::table('audit_logs')->where('event', 'permission_changed')->orderBy('id')->get()->all();
}

it('changes only the differing module and writes exactly one audit row', function () {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $before = ModulePermission::count();

    $this->actingAs($admin)->put("/settings/members/{$child->id}/permissions", payload(['wishlist' => 'view']))
        ->assertRedirect()->assertSessionHas('toast', ['key' => 'permissions.saved']);

    expect(ModulePermission::count())->toBe($before);
    expect(app(AccessGate::class)->forget($child))->toBeNull();
    expect(ModulePermission::where('user_id', $child->id)->where('module', 'wishlist')->first()->level)->toBe(AccessLevel::View);
    expect(ModulePermission::where('user_id', $child->id)->where('module', 'todo')->first()->level)->toBe(AccessLevel::Edit);

    $rows = auditRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->module)->toBe('wishlist');
    expect($rows[0]->actor_id)->toBe($admin->id);
    expect($rows[0]->target_user_id)->toBe($child->id);
    expect(json_decode($rows[0]->meta, true))->toEqual(['from' => 'edit', 'to' => 'view']);
});

it('writes nothing when nothing changed', function () {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($admin)->put("/settings/members/{$child->id}/permissions", payload())->assertRedirect();

    expect(auditRows())->toBeEmpty();
});

it('creates missing grant rows for a member without grants', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $this->actingAs($admin)->put("/settings/members/{$member->id}/permissions", payload(['finance' => 'view']))->assertRedirect();

    expect(auditRows())->toHaveCount(3);
    expect(ModulePermission::where('user_id', $member->id)->where('module', 'finance')->first()->level)->toBe(AccessLevel::View);
});

it('rejects a missing module, an unknown level and extra keys without changing anything', function (array $data) {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($admin)->put("/settings/members/{$child->id}/permissions", $data)->assertSessionHasErrors();

    expect(auditRows())->toBeEmpty();
    expect(ModulePermission::where('user_id', $child->id)->where('module', 'wishlist')->first()->level)->toBe(AccessLevel::Edit);
})->with([
    'missing module' => [['todo' => 'edit', 'wishlist' => 'view', 'finance' => 'none']],
    'unknown level' => [['todo' => 'edit', 'wishlist' => 'admin', 'finance' => 'none', 'health' => 'none']],
    'extra settings key' => [['todo' => 'edit', 'wishlist' => 'view', 'finance' => 'none', 'health' => 'none', 'settings' => 'edit']],
    'extra is_admin key' => [['todo' => 'edit', 'wishlist' => 'view', 'finance' => 'none', 'health' => 'none', 'is_admin' => true]],
]);

it('rolls the grant back when the audit write fails', function () {
    $admin = User::factory()->admin()->create();
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void
        {
            throw new RuntimeException('audit down');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($admin)->put("/settings/members/{$child->id}/permissions", payload(['wishlist' => 'none'])))
        ->toThrow(RuntimeException::class);

    expect(ModulePermission::where('user_id', $child->id)->where('module', 'wishlist')->first()->level)->toBe(AccessLevel::Edit);
});

it('applies the new grants on the member next request', function () {
    Route::middleware(['web', 'access:module,wishlist,edit'])->get('/__probe', fn () => 'ok')->name('probe');
    $admin = User::factory()->admin()->create();
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($child)->get('/__probe')->assertOk();
    $this->actingAs($admin)->put("/settings/members/{$child->id}/permissions", payload(['wishlist' => 'view']))->assertRedirect();
    $this->actingAs($child)->get('/__probe')->assertForbidden();
});

it('lets the admin grant themselves health view and audits it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put("/settings/members/{$admin->id}/permissions", payload(['health' => 'view']))->assertRedirect();

    expect(auditRows())->toHaveCount(3); // todo, wishlist (payload) and health
    expect($admin->fresh()->is_admin)->toBeTrue();
});

it('answers 404 for an unknown member as admin and 403 for everyone else', function () {
    $this->actingAs(User::factory()->admin()->create())->put('/settings/members/99999/permissions', payload())->assertNotFound();
    $this->actingAs(User::factory()->withPreset(Preset::Parent)->create())->put('/settings/members/99999/permissions', payload())->assertForbidden();
});
