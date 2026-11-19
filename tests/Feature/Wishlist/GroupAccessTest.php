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
