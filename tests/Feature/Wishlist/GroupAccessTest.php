<?php

use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Models\WishlistGroupMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A failed statement aborts the surrounding test transaction on Postgres, so run it inside a savepoint.
function grpAccFails(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

describe('schema', function () {
    it('creates a personal wish by default and a group wish with forGroup', function () {
        $personal = Wish::factory()->create();
        expect($personal->owner_id)->not->toBeNull()->and($personal->group_id)->toBeNull();

        $group = WishlistGroup::factory()->create();
        $wish = Wish::factory()->forGroup($group)->create();
        expect($wish->owner_id)->toBeNull()->and($wish->group_id)->toBe($group->id);
    });

    it('enforces owner XOR group with a CHECK', function () {
        $group = WishlistGroup::factory()->create();
        $user = User::factory()->create();

        grpAccFails(fn () => Wish::factory()->create(['owner_id' => $user->id, 'group_id' => $group->id]));
        grpAccFails(fn () => Wish::factory()->create(['owner_id' => null, 'group_id' => null]));
    });

    it('restricts deleting a group that still has wishes and cascades members otherwise', function () {
        $a = User::factory()->create();
        $group = WishlistGroup::factory()->withMembers([$a])->create();
        Wish::factory()->forGroup($group)->create();

        grpAccFails(fn () => DB::table('wishlist_groups')->where('id', $group->id)->delete());

        $empty = WishlistGroup::factory()->withMembers([$a])->create();
        $empty->delete();
        expect(WishlistGroupMember::query()->where('group_id', $empty->id)->count())->toBe(0);
    });

    it('rejects an unknown mode and duplicate members', function () {
        grpAccFails(fn () => DB::table('wishlist_groups')->insert(['name' => 'x', 'mode' => 'other', 'created_at' => now(), 'updated_at' => now()]));

        $a = User::factory()->create();
        $group = WishlistGroup::factory()->withMembers([$a])->create();
        grpAccFails(fn () => (new WishlistGroupMember)->forceFill(['group_id' => $group->id, 'user_id' => $a->id])->save());
    });

    it('creates member rows through the factory with gifts as default mode', function () {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $group = WishlistGroup::factory()->withMembers([$a, $b])->create();

        expect($group->members()->count())->toBe(2)->and($group->mode->value)->toBe('gifts');
    });

    it('has the new columns', function () {
        expect(Schema::hasColumns('wishes', ['group_id', 'received_by_id']))->toBeTrue();
    });
});

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\ModulePermission;
use App\Wishlist\Groups\GroupAccess;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishAccess;
use App\Wishlist\WishlistMembers;

function grpAccFresh(): GroupAccess
{
    return new GroupAccess(new AccessGate, app(WishlistMembers::class));
}

describe('access', function () {
    beforeEach(function () {
        $this->a = User::factory()->withPreset(Preset::Parent)->create();
        $this->b = User::factory()->withPreset(Preset::Parent)->create();
        $this->c = User::factory()->withPreset(Preset::Parent)->create();
        $this->admin = User::factory()->admin()->create();
        $this->viewOnly = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
        $this->gifts = WishlistGroup::factory()->withMembers([$this->a, $this->b])->create(['name' => 'Beta']);
        $this->shared = WishlistGroup::factory()->shared()->withMembers([$this->a, $this->b])->create(['name' => 'Alfa']);
    });

    it('applies gifts-mode rules', function () {
        $acc = grpAccFresh();
        $g = $this->gifts;

        foreach ([[$this->a, true], [$this->b, true], [$this->c, true], [$this->viewOnly, true], [$this->admin, false]] as [$u, $view]) {
            expect($acc->canView($u, $g))->toBe($view);
        }
        expect($acc->canManage($this->a, $g))->toBeTrue()
            ->and($acc->canManage($this->c, $g))->toBeFalse()
            ->and($acc->canManage($this->viewOnly, $g))->toBeFalse()
            ->and($acc->isRecipientSide($this->a, $g))->toBeTrue()
            ->and($acc->isRecipientSide($this->c, $g))->toBeFalse()
            ->and($acc->isGiverSide($this->c, $g))->toBeTrue()
            ->and($acc->isGiverSide($this->viewOnly, $g))->toBeTrue()
            ->and($acc->isGiverSide($this->a, $g))->toBeFalse();
    });

    it('applies shared-mode rules', function () {
        $acc = grpAccFresh();
        $g = $this->shared;

        expect($acc->canView($this->a, $g))->toBeTrue()
            ->and($acc->canView($this->c, $g))->toBeFalse()
            ->and($acc->isRecipientSide($this->a, $g))->toBeFalse()
            ->and($acc->isGiverSide($this->a, $g))->toBeTrue()
            ->and($acc->isGiverSide($this->c, $g))->toBeFalse()
            ->and($acc->canManage($this->a, $g))->toBeTrue();
    });

    it('keeps a member who lost edit read-only but recipient-side', function () {
        ModulePermission::query()->where('user_id', $this->a->id)->where('module', Module::Wishlist->value)
            ->update(['level' => AccessLevel::View->value]);
        $acc = grpAccFresh();

        expect($acc->canView($this->a, $this->gifts))->toBeTrue()
            ->and($acc->canManage($this->a, $this->gifts))->toBeFalse()
            ->and($acc->isRecipientSide($this->a, $this->gifts))->toBeTrue();
    });

    it('lists visible groups sorted by name', function () {
        $acc = grpAccFresh();

        expect($acc->visibleGroups($this->c)->pluck('name')->all())->toBe(['Beta'])
            ->and($acc->visibleGroups($this->a)->pluck('name')->all())->toBe(['Alfa', 'Beta'])
            ->and($acc->visibleGroups($this->admin)->all())->toBe([]);
    });

    it('exposes group sides through ViewerContext', function () {
        $ctx = ViewerContext::for($this->a);
        $groupWish = Wish::factory()->forGroup($this->gifts)->create();
        $sharedWish = Wish::factory()->forGroup($this->shared)->create();
        $personal = Wish::factory()->create(['owner_id' => $this->a->id]);
        $foreign = Wish::factory()->create(['owner_id' => $this->c->id]);

        expect($ctx->isGroupMember($this->gifts->id))->toBeTrue()
            ->and($ctx->isRecipientSideOfGroup($this->gifts->id))->toBeTrue()
            ->and($ctx->isRecipientSideOfGroup($this->shared->id))->toBeFalse()
            ->and($ctx->recipientSideGroupIds())->toBe([$this->gifts->id])
            ->and($ctx->isRecipientSideOfWish($groupWish))->toBeTrue()
            ->and($ctx->isRecipientSideOfWish($sharedWish))->toBeFalse()
            ->and($ctx->isRecipientSideOfWish($personal))->toBeTrue()
            ->and($ctx->isRecipientSideOfWish($foreign))->toBeFalse();
    });

    it('decides wish editing per group membership and keeps the personal rule', function () {
        $wa = new WishAccess(new AccessGate);
        $groupWish = Wish::factory()->forGroup($this->gifts)->create();
        $personal = Wish::factory()->create(['owner_id' => $this->a->id]);

        expect($wa->canEditWish($this->a, $groupWish))->toBeTrue()
            ->and($wa->canEditWish($this->c, $groupWish))->toBeFalse()
            ->and($wa->canEditWish($this->admin, $groupWish))->toBeFalse()
            ->and($wa->canEditWish($this->a, $personal))->toBe($wa->canEditList($this->a, $this->a->id))
            ->and($wa->canEditWish($this->c, $personal))->toBe($wa->canEditList($this->c, $this->a->id))
            ->and($wa->canEditWish($this->c, $personal))->toBeFalse();
    });

    it('offers only eligible candidates, viewer first', function () {
        $child = User::factory()->child()->withPreset(Preset::Child)->create();
        $names = collect(grpAccFresh()->candidates($this->b))->pluck('id')->all();

        expect($names[0])->toBe($this->b->id)->and($names)->not->toContain($child->id, $this->admin->id, $this->viewOnly->id);
    });
});
