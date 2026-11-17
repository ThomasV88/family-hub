<?php

namespace App\Wishlist;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Support\Facades\DB;

/** Replaces the set of members who manage an owner's wish list. Used by Settings > Members and by child creation. */
final class WishlistManagerSync
{
    /** A manager must be able to sign in and hold Wishlist Edit; a child or a view-only member cannot act for anyone. */
    public static function isEligible(User $member): bool
    {
        return $member->canSignIn() && app(AccessGate::class)->allows($member, Module::Wishlist, AccessLevel::Edit);
    }

    /**
     * Replace the owner's manager set, keeping rows that stay so their ids and created_at remain stable.
     *
     * @param  list<int>  $managerIds
     * @return list<int> manager ids that were newly added (for the reservation purge job)
     */
    public function sync(User $owner, array $managerIds, ?User $createdBy = null): array
    {
        $createdBy ??= auth()->user();

        return DB::transaction(function () use ($owner, $managerIds, $createdBy) {
            // Serializes with reserve/contribute (GivingGuard::assertStillGiver takes the shared side of this lock).
            User::query()->whereKey($owner->id)->lockForUpdate()->value('id');
            $existing = WishlistManager::query()->where('owner_id', $owner->id)->pluck('manager_id')->map(fn ($id) => (int) $id)->all();

            WishlistManager::query()->where('owner_id', $owner->id)->whereNotIn('manager_id', $managerIds)->delete();

            $added = array_values(array_diff($managerIds, $existing));
            foreach ($added as $managerId) {
                $row = new WishlistManager;
                $row->forceFill(['owner_id' => $owner->id, 'manager_id' => $managerId, 'created_by_id' => $createdBy?->id])->save();
            }

            return $added;
        });
    }
}
