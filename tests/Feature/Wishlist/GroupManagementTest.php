<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\ModulePermission;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistGroup;
use App\Models\WishlistGroupMember;
use App\Models\WishReservation;
use App\Wishlist\Giving\GroupReservationPurger;
use App\Wishlist\Groups\GroupMode;
use App\Wishlist\Groups\GroupService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->a = User::factory()->withPreset(Preset::Parent)->create();
    $this->b = User::factory()->withPreset(Preset::Parent)->create();
    $this->c = User::factory()->withPreset(Preset::Parent)->create();
});

function grpMgmtGroup(array $members, GroupMode $mode = GroupMode::Gifts): WishlistGroup
{
    $factory = WishlistGroup::factory()->withMembers($members);

    return ($mode === GroupMode::Shared ? $factory->shared() : $factory)->create();
}

function grpMgmtService(): GroupService
{
    return app(GroupService::class);
}

function grpMgmtError(Closure $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->first();
    }

    return 'no-error';
}

function grpMgmtIds(WishlistGroup $group): array
{
    return WishlistGroupMember::query()->where('group_id', $group->id)->orderBy('user_id')->pluck('user_id')->all();
}

function grpMgmtReserved(WishlistGroup $group): int
{
    return WishReservation::query()->whereIn('wish_id', Wish::query()->where('group_id', $group->id)->select('id'))->count();
}

describe('service', function () {
    it('creates a group with the actor and the invited members', function () {
        $group = grpMgmtService()->create($this->a, 'Our list', GroupMode::Gifts, [$this->b->id]);

        expect($group->name)->toBe('Our list')->and($group->mode)->toBe(GroupMode::Gifts)->and($group->created_by_id)->toBe($this->a->id)
            ->and(grpMgmtIds($group))->toBe([$this->a->id, $this->b->id])
            ->and(WishlistGroupMember::query()->where('group_id', $group->id)->pluck('added_by_id')->unique()->all())->toBe([$this->a->id]);
    });

    it('refuses ineligible members without writing anything', function (callable $make) {
        $id = $make();
        expect(grpMgmtError(fn () => grpMgmtService()->create($this->a, 'X', GroupMode::Gifts, [$id])))->toBe('wishlist.group.error.member_not_eligible')
            ->and(WishlistGroup::count())->toBe(0);
    })->with([
        'child that cannot sign in' => [fn () => User::factory()->withPreset(Preset::Child)->create(['sign_in_disabled' => true])->id],
        'view-only member' => [function () {
            $u = User::factory()->withPreset(Preset::Parent)->create();
            ModulePermission::where('user_id', $u->id)->where('module', Module::Wishlist->value)->update(['level' => AccessLevel::View->value]);

            return $u->id;
        }],
        'unknown id' => [fn () => 999999],
    ]);

    it('enforces the limits', function () {
        foreach (range(1, 10) as $i) {
            grpMgmtGroup([$this->a]);
        }
        expect(grpMgmtError(fn () => grpMgmtService()->create($this->a, 'X', GroupMode::Gifts, [])))->toBe('wishlist.group.error.too_many_groups');

        foreach (range(1, 10) as $i) {
            grpMgmtGroup([$this->b]);
        }
        expect(grpMgmtError(fn () => grpMgmtService()->create($this->c, 'X', GroupMode::Gifts, [$this->b->id])))->toBe('wishlist.group.error.member_limit');

        $many = User::factory()->withPreset(Preset::Parent)->count(12)->create()->pluck('id')->all();
        expect(grpMgmtError(fn () => grpMgmtService()->create($this->c, 'X', GroupMode::Gifts, $many)))->toBe('wishlist.group.error.too_many_members');
    });

    it('changes the mode only with a confirmation', function () {
        $group = grpMgmtGroup([$this->a, $this->b]);

        expect(grpMgmtError(fn () => grpMgmtService()->update($this->b, $group, null, GroupMode::Shared, false)))->toBe('wishlist.group.error.confirm_mode')
            ->and($group->fresh()->mode)->toBe(GroupMode::Gifts);

        expect(grpMgmtService()->update($this->b, $group, 'New', GroupMode::Shared, true))->toBeTrue()
            ->and($group->fresh()->mode)->toBe(GroupMode::Shared)->and($group->fresh()->name)->toBe('New');
        expect(grpMgmtService()->update($this->b, $group, 'Newer', GroupMode::Shared, false))->toBeFalse();
    });

    it('refuses non-members and members without Wishlist Edit', function () {
        $group = grpMgmtGroup([$this->a, $this->b]);
        $viewer = User::factory()->withPreset(Preset::Parent)->create();
        ModulePermission::where('user_id', $viewer->id)->where('module', Module::Wishlist->value)->update(['level' => AccessLevel::View->value]);
        WishlistGroupMember::query()->forceCreate(['group_id' => $group->id, 'user_id' => $viewer->id]);

        foreach ([$this->c, $viewer] as $actor) {
            expect(fn () => grpMgmtService()->update($actor, $group, 'Z', null, false))->toThrow(HttpException::class)
                ->and(fn () => grpMgmtService()->syncMembers($actor, $group, [$actor->id]))->toThrow(HttpException::class)
                ->and(fn () => grpMgmtService()->delete($actor, $group))->toThrow(HttpException::class);
        }
        expect(WishlistGroup::count())->toBe(1);
    });

    it('syncs members keeping stable rows and demands the actor', function () {
        $group = grpMgmtGroup([$this->a, $this->b]);
        $kept = WishlistGroupMember::query()->where('group_id', $group->id)->where('user_id', $this->a->id)->first();

        expect(grpMgmtError(fn () => grpMgmtService()->syncMembers($this->a, $group, [$this->b->id])))->toBe('wishlist.group.error.self_required');

        $result = grpMgmtService()->syncMembers($this->a, $group, [$this->a->id, $this->c->id]);

        expect($result)->toBe(['added' => [$this->c->id], 'removed' => [$this->b->id]])
            ->and(grpMgmtIds($group))->toBe([$this->a->id, $this->c->id])
            ->and(WishlistGroupMember::query()->find($kept->id)->created_at->equalTo($kept->created_at))->toBeTrue();
    });

    it('lets a member leave but not the last one', function () {
        $group = grpMgmtGroup([$this->a, $this->b], GroupMode::Shared);

        expect(grpMgmtService()->leave($this->a, $group))->toBe(GroupMode::Shared)->and(grpMgmtIds($group))->toBe([$this->b->id]);
        expect(fn () => grpMgmtService()->leave($this->b, $group))->toThrow(ValidationException::class)
            ->and(fn () => grpMgmtService()->leave($this->c, $group))->toThrow(HttpException::class);
    });

    it('deletes the group with its wishes, images, reservations and members', function () {
        Storage::fake('local');
        $group = grpMgmtGroup([$this->a, $this->b]);
        $wish = Wish::factory()->forGroup($group)->create();
        Storage::disk('local')->put('wishes/img.jpg', 'x');
        $wish->forceFill(['image_path' => 'wishes/img.jpg'])->save();
        $reservation = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $wish->id, 'user_id' => $this->c->id]);
        WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => $this->a->id, 'amount_minor' => 100]);
        $other = Wish::factory()->create();

        grpMgmtService()->delete($this->a, $group);

        expect(WishlistGroup::count())->toBe(0)->and(Wish::query()->pluck('id')->all())->toBe([$other->id])
            ->and(WishReservation::count())->toBe(0)->and(WishContribution::count())->toBe(0)
            ->and(WishlistGroupMember::count())->toBe(0)
            ->and(Storage::disk('local')->exists('wishes/img.jpg'))->toBeFalse();
    });
});

describe('purger', function () {
    it('purges a group, a member and the inconsistent holdings', function () {
        $gifts = grpMgmtGroup([$this->a]);
        $shared = grpMgmtGroup([$this->a], GroupMode::Shared);
        $personal = Wish::factory()->create();
        $gw = Wish::factory()->forGroup($gifts)->create();
        $sw = Wish::factory()->forGroup($shared)->create();

        $purger = app(GroupReservationPurger::class);

        // purgeGroup leaves other lists alone
        WishReservation::factory()->create(['wish_id' => $gw->id, 'user_id' => $this->b->id]);
        WishReservation::factory()->create(['wish_id' => $personal->id, 'user_id' => $this->b->id]);
        WishReservation::factory()->create(['wish_id' => $sw->id, 'user_id' => $this->a->id]);
        $purger->purgeGroup($gifts->id);
        expect(WishReservation::count())->toBe(2);

        // purgeMember: own reservations and shares in others' co-buys on this group only
        $gw2 = Wish::factory()->forGroup($gifts)->create();
        $gw3 = Wish::factory()->forGroup($gifts)->create();
        $mine = WishReservation::factory()->create(['wish_id' => $gw2->id, 'user_id' => $this->c->id]);
        $co = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $gw3->id, 'user_id' => $this->b->id]);
        WishContribution::factory()->create(['reservation_id' => $co->id, 'user_id' => $this->c->id, 'amount_minor' => 5]);
        $purger->purgeMember($gifts->id, $this->c->id);
        expect(WishReservation::query()->whereKey($mine->id)->exists())->toBeFalse()
            ->and(WishReservation::query()->whereKey($co->id)->exists())->toBeTrue()
            ->and(WishContribution::query()->where('user_id', $this->c->id)->count())->toBe(0)
            ->and(WishReservation::query()->where('wish_id', $sw->id)->count())->toBe(1);

        // purgeAll: member holding on gifts goes, outsider holding on gifts stays, outsider holding on shared goes
        $gw4 = Wish::factory()->forGroup($gifts)->create();
        $sw2 = Wish::factory()->forGroup($shared)->create();
        WishReservation::factory()->create(['wish_id' => $gw4->id, 'user_id' => $this->a->id]);
        WishReservation::factory()->create(['wish_id' => $sw2->id, 'user_id' => $this->c->id]);
        $purger->purgeAll();
        expect(WishReservation::query()->where('wish_id', $gw4->id)->exists())->toBeFalse()
            ->and(WishReservation::query()->where('wish_id', $gw3->id)->where('user_id', $this->b->id)->exists())->toBeTrue()
            ->and(WishReservation::query()->where('wish_id', $sw2->id)->exists())->toBeFalse()
            ->and(WishReservation::query()->where('wish_id', $sw->id)->where('user_id', $this->a->id)->exists())->toBeTrue()
            ->and(WishReservation::query()->where('wish_id', $personal->id)->count())->toBe(1);
    });
});

describe('routes', function () {
    it('creates a group and lands on it, ignoring mass-assigned keys', function () {
        $response = $this->actingAs($this->a)->post('/wishlist/groups', [
            'name' => '  Us  ', 'mode' => 'gifts', 'member_ids' => [$this->b->id], 'created_by_id' => $this->c->id, 'id' => 999,
        ]);

        $group = WishlistGroup::query()->firstOrFail();
        $response->assertStatus(303)->assertRedirect('/wishlist?group='.$group->id)->assertSessionHas('toast.key', 'wishlist.toast.group_created');
        expect($group->name)->toBe('Us')->and($group->created_by_id)->toBe($this->a->id)->and($group->id)->not->toBe(999);
    });

    it('validates the create payload', function (array $payload) {
        $this->actingAs($this->a)->postJson('/wishlist/groups', $payload)->assertStatus(422);
        expect(WishlistGroup::count())->toBe(0);
    })->with([
        'no name' => [['name' => ' ', 'mode' => 'gifts']],
        'bad mode' => [['name' => 'x', 'mode' => 'other']],
        'no mode' => [['name' => 'x']],
        'duplicate ids' => [['name' => 'x', 'mode' => 'gifts', 'member_ids' => [5, 5]]],
        'long name' => [['name' => str_repeat('a', 81), 'mode' => 'gifts']],
    ]);

    it('renames and switches mode, cancelling every reservation identically with or without them', function () {
        $with = grpMgmtGroup([$this->a, $this->b]);
        $twin = grpMgmtGroup([$this->a, $this->b]);
        $wish = Wish::factory()->forGroup($with)->create();
        $reservation = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $wish->id, 'user_id' => $this->c->id]);
        WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => User::factory()->create()->id, 'amount_minor' => 100]);
        Wish::factory()->forGroup($twin)->create();

        $this->actingAs($this->b)->patchJson('/wishlist/groups/'.$with->id, ['mode' => 'shared'])->assertStatus(422);
        expect(grpMgmtReserved($with))->toBe(1)->and($with->fresh()->mode)->toBe(GroupMode::Gifts);

        $r1 = $this->actingAs($this->b)->from('/wishlist')->patch('/wishlist/groups/'.$with->id, ['mode' => 'shared', 'confirm_mode_change' => true]);
        $r2 = $this->actingAs($this->b)->from('/wishlist')->patch('/wishlist/groups/'.$twin->id, ['mode' => 'shared', 'confirm_mode_change' => true]);

        expect($r1->getStatusCode())->toBe($r2->getStatusCode())->and($r1->headers->get('Location'))->toBe($r2->headers->get('Location'))
            ->and(session('toast.key'))->toBe('wishlist.toast.group_saved')
            ->and(grpMgmtReserved($with))->toBe(0)->and(WishContribution::count())->toBe(0)
            ->and($with->fresh()->mode)->toBe(GroupMode::Shared);

        $this->actingAs($this->b)->patch('/wishlist/groups/'.$with->id, ['name' => 'Renamed'])->assertStatus(303);
        expect($with->fresh()->name)->toBe('Renamed');
    });

    it('drops reservations of members added to a gifts list', function () {
        $group = grpMgmtGroup([$this->a]);
        $wish = Wish::factory()->forGroup($group)->create();
        WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $this->b->id]);

        $this->actingAs($this->a)->put('/wishlist/groups/'.$group->id.'/members', ['member_ids' => [$this->a->id, $this->b->id]])
            ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.group_saved');

        expect(grpMgmtReserved($group))->toBe(0)->and(grpMgmtIds($group))->toBe([$this->a->id, $this->b->id]);
    });

    it('drops reservations and shares of members removed from a shared list', function () {
        $group = grpMgmtGroup([$this->a, $this->b, $this->c], GroupMode::Shared);
        $wish = Wish::factory()->forGroup($group)->create();
        WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $this->c->id]);
        $co = WishReservation::factory()->cobuyOpen()->create(['wish_id' => Wish::factory()->forGroup($group)->create()->id, 'user_id' => $this->a->id]);
        WishContribution::factory()->create(['reservation_id' => $co->id, 'user_id' => $this->c->id, 'amount_minor' => 5]);

        $this->actingAs($this->a)->put('/wishlist/groups/'.$group->id.'/members', ['member_ids' => [$this->a->id, $this->b->id]])->assertStatus(303);

        expect(WishReservation::query()->where('user_id', $this->c->id)->count())->toBe(0)
            ->and(WishReservation::query()->whereKey($co->id)->exists())->toBeTrue()->and(WishContribution::query()->where('user_id', $this->c->id)->count())->toBe(0);
    });

    it('lets a member leave, purges on shared lists and refuses the last member', function () {
        $group = grpMgmtGroup([$this->a, $this->b], GroupMode::Shared);
        WishReservation::factory()->create(['wish_id' => Wish::factory()->forGroup($group)->create()->id, 'user_id' => $this->a->id]);

        $this->actingAs($this->a)->delete('/wishlist/groups/'.$group->id.'/membership')
            ->assertStatus(303)->assertRedirect('/wishlist')->assertSessionHas('toast.key', 'wishlist.toast.group_left');
        expect(grpMgmtIds($group))->toBe([$this->b->id])->and(grpMgmtReserved($group))->toBe(0);

        $this->actingAs($this->b)->delete('/wishlist/groups/'.$group->id.'/membership')
            ->assertSessionHasErrors(['member_ids' => 'wishlist.group.error.last_member']);
        expect(grpMgmtIds($group))->toBe([$this->b->id])->and(WishlistGroup::query()->whereKey($group->id)->exists())->toBeTrue();
    });

    it('deletes a group', function () {
        $group = grpMgmtGroup([$this->a]);
        Wish::factory()->forGroup($group)->create();

        $this->actingAs($this->a)->delete('/wishlist/groups/'.$group->id)
            ->assertStatus(303)->assertRedirect('/wishlist')->assertSessionHas('toast.key', 'wishlist.toast.group_deleted');
        expect(WishlistGroup::count())->toBe(0)->and(Wish::count())->toBe(0);
    });

    it('answers 403 identically to a non-member, an unknown id and the admin', function () {
        $group = grpMgmtGroup([$this->a, $this->b]);
        $admin = User::factory()->admin()->create();
        $calls = [
            ['patch', '/wishlist/groups/%d', ['name' => 'x']],
            ['put', '/wishlist/groups/%d/members', ['member_ids' => [1]]],
            ['delete', '/wishlist/groups/%d/membership', []],
            ['delete', '/wishlist/groups/%d', []],
        ];

        foreach ($calls as [$method, $uri, $data]) {
            $nonMember = $this->actingAs($this->c)->{$method.'Json'}(sprintf($uri, $group->id), $data);
            $unknown = $this->actingAs($this->c)->{$method.'Json'}(sprintf($uri, 999999), $data);
            $nonMember->assertForbidden();
            $unknown->assertForbidden();
            expect($nonMember->json('message'))->toBe($unknown->json('message'));
            $this->actingAs($admin)->{$method.'Json'}(sprintf($uri, $group->id), $data)->assertForbidden();
        }
        expect(WishlistGroup::count())->toBe(1)->and(grpMgmtIds($group))->toBe([$this->a->id, $this->b->id]);
    });

    it('sweeps stray reservations in wishlist:prune', function () {
        $group = grpMgmtGroup([$this->a]);
        $r = WishReservation::factory()->create(['wish_id' => Wish::factory()->forGroup($group)->create()->id, 'user_id' => $this->a->id]);

        $this->artisan('wishlist:prune')->assertSuccessful();

        expect(WishReservation::query()->whereKey($r->id)->exists())->toBeFalse();
    });
});
