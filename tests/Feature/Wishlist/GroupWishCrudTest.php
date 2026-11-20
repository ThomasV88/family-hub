<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\Catalog\WishPresenter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ImageFixtures;

describe('group wish crud', function () {
    beforeEach(function () {
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');
        Queue::fake();
        $this->a = User::factory()->withPreset(Preset::Parent)->create();
        $this->b = User::factory()->withPreset(Preset::Parent)->create();
        $this->outsider = User::factory()->withPreset(Preset::Parent)->create();
        $this->admin = User::factory()->admin()->create();
        $this->noEdit = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
        $this->group = WishlistGroup::factory()->withMembers([$this->a, $this->b, $this->noEdit])->create(['name' => 'Couple']);
        $this->shared = WishlistGroup::factory()->shared()->withMembers([$this->a, $this->b])->create(['name' => 'Home']);
    });

    it('creates a group wish for a member and redirects to the group', function () {
        $this->actingAs($this->a)->post('/wishlist/wishes', ['title' => 'Sofa', 'group_id' => $this->group->id])
            ->assertStatus(303)->assertRedirect('/wishlist?group='.$this->group->id);

        $wish = Wish::query()->sole();
        expect($wish->owner_id)->toBeNull()->and($wish->group_id)->toBe($this->group->id)->and($wish->created_by_id)->toBe($this->a->id);
    });

    it('refuses owner and group together, foreign and unknown groups', function () {
        $this->actingAs($this->a)->post('/wishlist/wishes', ['title' => 'X', 'group_id' => $this->group->id, 'owner_id' => $this->a->id])
            ->assertSessionHasErrors(['owner_id', 'group_id']);
        $this->actingAs($this->outsider)->post('/wishlist/wishes', ['title' => 'X', 'group_id' => $this->group->id])->assertForbidden();
        $this->actingAs($this->admin)->post('/wishlist/wishes', ['title' => 'X', 'group_id' => $this->group->id])->assertForbidden();
        $this->actingAs($this->noEdit)->post('/wishlist/wishes', ['title' => 'X', 'group_id' => $this->group->id])->assertForbidden();
        $this->actingAs($this->a)->post('/wishlist/wishes', ['title' => 'X', 'group_id' => 999999])->assertForbidden();
        expect(Wish::count())->toBe(0);
    });

    it('lets members update, delete, mark and unmark received, others get 403', function () {
        $wish = Wish::factory()->forGroup($this->group)->create(['title' => 'Old']);

        foreach ([$this->outsider, $this->admin, $this->noEdit] as $u) {
            $this->actingAs($u)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'Hack'])->assertForbidden();
            $this->actingAs($u)->post("/wishlist/wishes/{$wish->id}/received")->assertForbidden();
            $this->actingAs($u)->delete("/wishlist/wishes/{$wish->id}")->assertForbidden();
        }
        expect($wish->fresh()->title)->toBe('Old');

        $this->actingAs($this->b)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'New'])->assertStatus(303);
        expect($wish->fresh()->title)->toBe('New');

        $this->actingAs($this->b)->post("/wishlist/wishes/{$wish->id}/received")->assertStatus(303);
        $fresh = $wish->fresh();
        expect($fresh->received_at)->not->toBeNull()->and($fresh->received_by_id)->toBe($this->b->id);

        $this->actingAs($this->a)->delete("/wishlist/wishes/{$wish->id}/received")->assertStatus(303);
        $fresh = $wish->fresh();
        expect($fresh->received_at)->toBeNull()->and($fresh->received_by_id)->toBeNull();

        $this->actingAs($this->a)->delete("/wishlist/wishes/{$wish->id}")
            ->assertStatus(303)->assertRedirect('/wishlist?group='.$this->group->id);
        expect(Wish::count())->toBe(0);
    });

    it('leaves received_by_id null for a personal wish', function () {
        $wish = Wish::factory()->create(['owner_id' => $this->a->id]);
        $this->actingAs($this->a)->post("/wishlist/wishes/{$wish->id}/received")->assertStatus(303);
        expect($wish->fresh())->received_at->not->toBeNull()->received_by_id->toBeNull();
    });

    it('handles images of group wishes', function () {
        $wish = Wish::factory()->forGroup($this->group)->create();
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, ImageFixtures::jpeg());
        $file = fn () => new UploadedFile($path, 'x.jpg', 'image/jpeg', null, true);

        $this->actingAs($this->outsider)->post("/wishlist/wishes/{$wish->id}/image", ['image' => $file()])->assertForbidden();
        $this->actingAs($this->outsider)->delete("/wishlist/wishes/{$wish->id}/image")->assertForbidden();
        $this->actingAs($this->outsider)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertForbidden();
        $this->actingAs($this->a)->post("/wishlist/wishes/{$wish->id}/image", ['image' => $file()])->assertStatus(303);
        expect($wish->fresh()->image_status)->toBe('ready');
        $this->actingAs($this->b)->delete("/wishlist/wishes/{$wish->id}/image")->assertStatus(303);
    });

    it('serves group wish images by group visibility', function () {
        $active = Wish::factory()->forGroup($this->group)->create();
        $sharedWish = Wish::factory()->forGroup($this->shared)->create();
        $received = Wish::factory()->forGroup($this->group)->create(['received_at' => now()]);
        foreach ([$active, $sharedWish, $received] as $w) {
            $w->forceFill(['image_status' => 'ready', 'image_path' => "wishes/{$w->id}/full.jpg", 'thumb_path' => "wishes/{$w->id}/thumb.jpg"])->save();
            Storage::disk('local')->put("wishes/{$w->id}/full.jpg", 'jpeg');
            Storage::disk('local')->put("wishes/{$w->id}/thumb.jpg", 'jpeg');
        }

        $this->actingAs($this->a)->get("/wishlist/wishes/{$active->id}/image/thumb")->assertOk();
        $this->actingAs($this->outsider)->get("/wishlist/wishes/{$active->id}/image/thumb")->assertOk();
        $this->actingAs($this->outsider)->get("/wishlist/wishes/{$sharedWish->id}/image/thumb")->assertNotFound();
        $this->actingAs($this->b)->get("/wishlist/wishes/{$sharedWish->id}/image/thumb")->assertOk();
        $this->actingAs($this->a)->get("/wishlist/wishes/{$received->id}/image/thumb")->assertOk();
        $this->actingAs($this->outsider)->get("/wishlist/wishes/{$received->id}/image/thumb")->assertNotFound();
    });
});

describe('list props', function () {
    beforeEach(function () {
        $this->a = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Anna']);
        $this->b = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Boris']);
        $this->outsider = User::factory()->withPreset(Preset::Parent)->create();
        $this->gifts = WishlistGroup::factory()->withMembers([$this->a, $this->b])->create(['name' => 'Couple']);
        $this->shared = WishlistGroup::factory()->shared()->withMembers([$this->a, $this->b])->create(['name' => 'Home']);
        $this->page = fn (WishlistGroup $g, User $u, array $query = []) => app(ListPage::class)
            ->groupProps($g, $u, Request::create('/wishlist', 'GET', $query));
    });

    it('builds the member view of a gifts group', function () {
        $active = Wish::factory()->forGroup($this->gifts)->create();
        $done = Wish::factory()->forGroup($this->gifts)->create(['received_at' => now()]);
        $done->forceFill(['received_by_id' => $this->b->id])->save();

        $props = ($this->page)($this->gifts, $this->a);
        expect($props['list']['kind'])->toBe('group')
            ->and($props['list']['owner'])->toBe(['id' => null, 'name' => 'Couple'])
            ->and($props['list']['group'])->toBe(['id' => $this->gifts->id, 'name' => 'Couple', 'mode' => 'gifts', 'is_member' => true, 'can_manage' => true,
                'members' => [['id' => $this->a->id, 'name' => 'Anna'], ['id' => $this->b->id, 'name' => 'Boris']]])
            ->and($props['list']['viewer_is_recipient'])->toBeTrue()
            ->and($props['list']['viewer_is_manager'])->toBeFalse()
            ->and($props['list']['can_edit'])->toBeTrue()
            ->and(array_column($props['list']['wishes'], 'id'))->toBe([$active->id])
            ->and($props['list']['received'][0]['received_by'])->toBe(['id' => $this->b->id, 'name' => 'Boris'])
            ->and($props['list']['wishes'][0])->toHaveKey('received_by');
    });

    it('builds the giver view of a gifts group without received', function () {
        Wish::factory()->forGroup($this->gifts)->create(['received_at' => now()]);
        $props = ($this->page)($this->gifts, $this->outsider);
        expect($props['list']['viewer_is_recipient'])->toBeFalse()
            ->and($props['list']['can_edit'])->toBeFalse()
            ->and($props['list']['group']['is_member'])->toBeFalse()
            ->and($props['list'])->not->toHaveKey('received');
    });

    it('builds the member view of a shared group', function () {
        $props = ($this->page)($this->shared, $this->a);
        expect($props['list']['viewer_is_recipient'])->toBeFalse()
            ->and($props['list']['can_edit'])->toBeTrue()
            ->and($props['list'])->toHaveKey('received');
    });

    it('validates the selected wish', function () {
        $done = Wish::factory()->forGroup($this->gifts)->create(['received_at' => now()]);
        $active = Wish::factory()->forGroup($this->gifts)->create();

        expect(($this->page)($this->gifts, $this->a, ['wish' => 'new'])['selected'])->toBe('new')
            ->and(($this->page)($this->gifts, $this->a, ['wish' => (string) $done->id])['selected'])->toBe($done->id)
            ->and(($this->page)($this->gifts, $this->outsider, ['wish' => (string) $active->id])['selected'])->toBe($active->id);
        expect(fn () => ($this->page)($this->gifts, $this->outsider, ['wish' => 'new']))->toThrow(HttpException::class);
        expect(fn () => ($this->page)($this->gifts, $this->outsider, ['wish' => (string) $done->id]))->toThrow(HttpException::class);
    });

    it('keeps personal wish keys unchanged and runs no reservation SQL', function () {
        $personal = Wish::factory()->create(['owner_id' => $this->a->id]);
        expect(array_keys(app(WishPresenter::class)->present($personal)))->not->toContain('received_by');
        Wish::factory()->forGroup($this->gifts)->create();

        DB::enableQueryLog();
        ($this->page)($this->gifts, $this->a);
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        expect($sql)->not->toContain('wish_reservations')->not->toContain('wish_contributions');
    });
});
