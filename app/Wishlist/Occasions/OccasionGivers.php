<?php

namespace App\Wishlist\Occasions;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ModulePermission;
use App\Models\User;
use App\Models\WishlistManager;

/**
 * Who may be reminded about an occasion: members with wishlist edit, minus the person whose occasion it is
 * and that person's designated managers. Occasions without an owner (Christmas, family-wide) go to all givers.
 */
final class OccasionGivers
{
    public function __construct(private readonly AccessGate $gate) {}

    /** @return list<User> */
    public function for(OccasionOccurrence $occurrence): array
    {
        $excluded = $this->excludedIds($occurrence);

        $users = User::query()
            ->whereIn('users.id', ModulePermission::query()
                ->select('user_id')
                ->where('module', Module::Wishlist->value)
                ->where('level', '>=', AccessLevel::Edit->value))
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('users.id', $excluded))
            ->orderBy('id')
            ->get();

        return $users->filter(fn (User $u) => $this->hasEdit($u))->values()->all();
    }

    public function isGiver(User $user, OccasionOccurrence $occurrence): bool
    {
        return ! in_array($user->id, $this->excludedIds($occurrence), true) && $this->hasEdit($user);
    }

    private function hasEdit(User $user): bool
    {
        // Long-lived process: never trust a cached grant.
        $this->gate->forget($user);

        return $this->gate->allows($user, Module::Wishlist, AccessLevel::Edit);
    }

    /** @return list<int> */
    private function excludedIds(OccasionOccurrence $occurrence): array
    {
        if ($occurrence->ownerId === null) {
            return [];
        }

        return [
            $occurrence->ownerId,
            ...WishlistManager::query()->where('owner_id', $occurrence->ownerId)->pluck('manager_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
