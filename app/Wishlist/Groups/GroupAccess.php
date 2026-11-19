<?php

namespace App\Wishlist\Groups;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\WishlistGroup;
use App\Wishlist\WishlistManagerSync;
use App\Wishlist\WishlistMembers;
use Collator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Record-level rights on group wishlists. No admin bypass: admin status grants nothing here.
 * Reservation-blind by design (arch-tested).
 */
final class GroupAccess
{
    public function __construct(private readonly AccessGate $gate, private readonly WishlistMembers $members) {}

    public function isMember(User $user, WishlistGroup $group): bool
    {
        return DB::table('wishlist_group_members')->where('group_id', $group->id)->where('user_id', $user->id)->exists();
    }

    /** Members always (with Wishlist View); non-members only of a gifts-mode list, as givers. */
    public function canView(User $user, WishlistGroup $group): bool
    {
        if (! $this->gate->allows($user, Module::Wishlist, AccessLevel::View)) {
            return false;
        }

        return $group->mode === GroupMode::Gifts || $this->isMember($user, $group);
    }

    public function canManage(User $user, WishlistGroup $group): bool
    {
        return $this->gate->allows($user, Module::Wishlist, AccessLevel::Edit) && $this->isMember($user, $group);
    }

    public function isRecipientSide(User $user, WishlistGroup $group): bool
    {
        return $group->mode === GroupMode::Gifts && $this->isMember($user, $group);
    }

    public function isGiverSide(User $user, WishlistGroup $group): bool
    {
        return $this->canView($user, $group) && ! $this->isRecipientSide($user, $group);
    }

    /** Groups the viewer can see: all of their own plus every gifts-mode group, by name. @return Collection<int, WishlistGroup> */
    public function visibleGroups(User $user): Collection
    {
        if (! $this->gate->allows($user, Module::Wishlist, AccessLevel::View)) {
            return collect();
        }

        $groups = WishlistGroup::query()
            ->where(fn ($q) => $q->where('mode', GroupMode::Gifts->value)
                ->orWhereIn('id', fn ($m) => $m->select('group_id')->from('wishlist_group_members')->where('user_id', $user->id)))
            ->get();

        $collator = new Collator(app()->getLocale());

        return $groups
            ->sort(fn (WishlistGroup $a, WishlistGroup $b) => (($collator->compare($a->name, $b->name) ?: 0) ?: $a->id <=> $b->id))
            ->values();
    }

    /** @return list<int> */
    public function memberIds(WishlistGroup $group): array
    {
        return DB::table('wishlist_group_members')->where('group_id', $group->id)->orderBy('user_id')
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<array{id: int, name: string}> names only, locale-sorted */
    public function members(WishlistGroup $group): array
    {
        $rows = User::query()->whereIn('id', $this->memberIds($group))->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all();

        $collator = new Collator(app()->getLocale());
        usort($rows, fn (array $a, array $b) => (($collator->compare($a['name'], $b['name']) ?: 0) ?: $a['id'] <=> $b['id']));

        return array_values($rows);
    }

    /** People who can be added to a group: viewer first, then locale-sorted, all eligible (G-04). @return list<array{id: int, name: string}> */
    public function candidates(User $viewer): array
    {
        $rows = [];
        foreach ($this->members->forViewer($viewer) as $m) {
            $user = User::query()->find($m['id']);
            if ($user !== null && $this->isEligible($user)) {
                $rows[] = ['id' => $m['id'], 'name' => $m['name']];
            }
        }

        return $rows;
    }

    public function isEligible(User $user): bool
    {
        return WishlistManagerSync::isEligible($user);
    }
}
