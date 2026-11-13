<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->withPreset(Preset::Child)->create();
    $this->a = User::factory()->withPreset(Preset::Parent)->create();
    $this->b = User::factory()->withPreset(Preset::Parent)->create();
    $this->url = fn (?int $id = null) => '/settings/members/'.($id ?? $this->member->id).'/wishlist-managers';
});

it('designates managers, keeps unchanged rows and clears on empty', function () {
    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => [$this->a->id, $this->b->id]])->assertRedirect()
        ->assertSessionHas('toast.key', 'settings.members.wishlist_managers.saved');

    $rows = WishlistManager::query()->where('owner_id', $this->member->id)->orderBy('manager_id')->get();
    expect($rows->pluck('manager_id')->all())->toBe([$this->a->id, $this->b->id])
        ->and($rows->pluck('created_by_id')->unique()->all())->toBe([$this->admin->id]);
    $keptId = $rows->firstWhere('manager_id', $this->b->id)->id;

    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => [$this->b->id]])->assertRedirect();
    $rows = WishlistManager::query()->where('owner_id', $this->member->id)->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->id)->toBe($keptId)->and($rows[0]->manager_id)->toBe($this->b->id);

    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => []])->assertRedirect();
    expect(WishlistManager::count())->toBe(0);
});

it('does not touch other owners rows', function () {
    WishlistManager::factory()->create(['owner_id' => $this->a->id, 'manager_id' => $this->b->id]);

    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => [$this->a->id]])->assertRedirect();

    expect(WishlistManager::count())->toBe(2);
});

it('rejects invalid manager sets without changing anything', function (callable $payload) {
    WishlistManager::factory()->create(['owner_id' => $this->member->id, 'manager_id' => $this->a->id]);

    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => $payload($this)])->assertStatus(422);

    expect(WishlistManager::query()->pluck('manager_id')->all())->toBe([$this->a->id]);
})->with([
    'self' => [fn ($t) => [$t->member->id]],
    'unknown id' => [fn ($t) => [999999]],
    'duplicate' => [fn ($t) => [$t->b->id, $t->b->id]],
    'non-integer' => [fn ($t) => ['abc']],
    'too many' => [fn ($t) => array_fill(0, 11, $t->b->id)],
]);

it('keeps non-admins out and answers 404 only for admins on an unknown member', function () {
    $this->actingAs($this->a)->putJson(($this->url)(), ['manager_ids' => [$this->b->id]])->assertForbidden();
    $this->actingAs($this->a)->putJson(($this->url)(999999), ['manager_ids' => []])->assertForbidden();
    $this->actingAs($this->admin)->putJson(($this->url)(999999), ['manager_ids' => []])->assertNotFound();
    auth()->logout();
    $this->putJson(($this->url)(), ['manager_ids' => []])->assertUnauthorized();
    expect(WishlistManager::count())->toBe(0);
});

it('exposes wishlistManagerIds per member on the Members page', function () {
    WishlistManager::factory()->create(['owner_id' => $this->member->id, 'manager_id' => $this->a->id]);

    $this->actingAs($this->admin)->get('/settings/members')->assertInertia(fn (Assert $p) => $p
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->member->id)['wishlistManagerIds'] === [$this->a->id]
            && collect($members)->firstWhere('id', $this->b->id)['wishlistManagerIds'] === []));
});

it('writes no audit entry', function () {
    $before = DB::table('audit_logs')->count();

    $this->actingAs($this->admin)->putJson(($this->url)(), ['manager_ids' => [$this->a->id]])->assertRedirect();

    expect(DB::table('audit_logs')->count())->toBe($before);
});
