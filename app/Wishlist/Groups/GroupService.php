<?php

namespace App\Wishlist\Groups;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Models\WishlistGroupMember;
use App\Wishlist\Catalog\WishImageStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and manages group lists. Reservation-blind by design (arch-tested): the controllers pair these calls
 * with the reservation purger inside one transaction. Every method locks the group row first and re-checks rights.
 */
final class GroupService
{
    public function __construct(private readonly GroupAccess $access, private readonly WishImageStore $images) {}

    /** @param  list<int>  $otherIds */
    public function create(User $actor, string $name, GroupMode $mode, array $otherIds): WishlistGroup
    {
        return DB::transaction(function () use ($actor, $name, $mode, $otherIds) {
            // Serialize the per-person group count for this actor.
            User::query()->whereKey($actor->id)->lockForUpdate()->value('id');

            $ids = array_values(array_unique(array_merge([$actor->id], array_map('intval', $otherIds))));
            $others = array_values(array_diff($ids, [$actor->id]));

            if ($this->groupCount($actor->id) >= (int) config('wishlist.group_max_per_user')) {
                throw ValidationException::withMessages(['name' => 'wishlist.group.error.too_many_groups']);
            }
            if (count($ids) > (int) config('wishlist.group_max_members')) {
                throw ValidationException::withMessages(['member_ids' => 'wishlist.group.error.too_many_members']);
            }
            $this->assertAddable($others);

            $group = new WishlistGroup;
            $group->forceFill(['name' => $name, 'mode' => $mode, 'created_by_id' => $actor->id])->save();

            foreach ($ids as $userId) {
                $this->insertMember($group->id, $userId, $actor->id);
            }

            return $group;
        });
    }

    /** @return bool whether the mode changed */
    public function update(User $actor, WishlistGroup $group, ?string $name, ?GroupMode $mode, bool $confirmed): bool
    {
        return DB::transaction(function () use ($actor, $group, $name, $mode, $confirmed) {
            $locked = $this->lockManaged($actor, $group);

            $modeChanges = $mode !== null && $mode !== $locked->mode;
            if ($modeChanges && ! $confirmed) {
                throw ValidationException::withMessages(['confirm_mode_change' => 'wishlist.group.error.confirm_mode']);
            }

            if ($name !== null) {
                $locked->name = $name;
            }
            if ($modeChanges) {
                $locked->forceFill(['mode' => $mode]);
            }
            $locked->save();

            return $modeChanges;
        });
    }

    /**
     * Replace the member set; rows that stay keep their id and created_at.
     *
     * @param  list<int>  $ids
     * @return array{added: list<int>, removed: list<int>}
     */
    public function syncMembers(User $actor, WishlistGroup $group, array $ids): array
    {
        return DB::transaction(function () use ($actor, $group, $ids) {
            $locked = $this->lockManaged($actor, $group);

            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (! in_array($actor->id, $ids, true)) {
                throw ValidationException::withMessages(['member_ids' => 'wishlist.group.error.self_required']);
            }
            if (count($ids) > (int) config('wishlist.group_max_members')) {
                throw ValidationException::withMessages(['member_ids' => 'wishlist.group.error.too_many_members']);
            }

            $existing = $this->access->memberIds($locked);
            $added = array_values(array_diff($ids, $existing));
            $removed = array_values(array_diff($existing, $ids));
            $this->assertAddable($added);

            WishlistGroupMember::query()->where('group_id', $locked->id)->whereIn('user_id', $removed)->delete();
            foreach ($added as $userId) {
                $this->insertMember($locked->id, $userId, $actor->id);
            }

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /** @return GroupMode the mode at the time of leaving */
    public function leave(User $actor, WishlistGroup $group): GroupMode
    {
        return DB::transaction(function () use ($actor, $group) {
            $locked = $this->lock($group);
            abort_unless($this->access->isMember($actor, $locked), 403);
            abort_if(count($this->access->memberIds($locked)) <= 1, 409);

            WishlistGroupMember::query()->where('group_id', $locked->id)->where('user_id', $actor->id)->delete();

            return $locked->mode;
        });
    }

    public function delete(User $actor, WishlistGroup $group): void
    {
        DB::transaction(function () use ($actor, $group) {
            $locked = $this->lockManaged($actor, $group);

            foreach (Wish::query()->where('group_id', $locked->id)->get() as $wish) {
                $this->images->remove($wish);
            }
            Wish::query()->where('group_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    private function lock(WishlistGroup $group): WishlistGroup
    {
        $locked = WishlistGroup::query()->whereKey($group->id)->lockForUpdate()->first();
        abort_if($locked === null, 403);

        return $locked;
    }

    private function lockManaged(User $actor, WishlistGroup $group): WishlistGroup
    {
        $locked = $this->lock($group);
        abort_unless($this->access->canManage($actor, $locked), 403);

        return $locked;
    }

    /** @param  list<int>  $ids people about to join: eligible and under their group limit */
    private function assertAddable(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $user = $users->get($id);
            if ($user === null || ! $this->access->isEligible($user)) {
                throw ValidationException::withMessages(['member_ids' => 'wishlist.group.error.member_not_eligible']);
            }
            if ($this->groupCount($id) >= (int) config('wishlist.group_max_per_user')) {
                throw ValidationException::withMessages(['member_ids' => 'wishlist.group.error.member_limit']);
            }
        }
    }

    private function groupCount(int $userId): int
    {
        return DB::table('wishlist_group_members')->where('user_id', $userId)->count();
    }

    private function insertMember(int $groupId, int $userId, int $addedById): void
    {
        (new WishlistGroupMember)->forceFill(['group_id' => $groupId, 'user_id' => $userId, 'added_by_id' => $addedById])->save();
    }
}
