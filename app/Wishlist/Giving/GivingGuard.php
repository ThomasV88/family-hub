<?php

namespace App\Wishlist\Giving;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Models\WishlistManager;
use App\Wishlist\Groups\GroupAccess;
use App\Wishlist\Groups\GroupMode;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishlistMembers;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Fail-closed gate (personal and group lists) in front of everything that reads or writes reservations: the recipient (and a designated
 * manager) must never get past it, and must not be able to tell whether a reservation exists.
 */
final class GivingGuard
{
    public function __construct(private readonly WishlistMembers $members, private readonly GroupAccess $groups) {}

    /** Defense in depth for services and presenters: a routing mistake becomes an exception, never a leak. */
    public function assertGiver(ViewerContext $context, int $ownerId): void
    {
        if ($context->isRecipientSideOf($ownerId)) {
            throw new LogicException('Recipient-side viewers have no access to reservations.');
        }
    }

    /** Wish-based variant of assertGiver: covers group wishes (gifts-mode members are recipient-side). */
    public function assertGiverOfWish(ViewerContext $context, Wish $wish): void
    {
        if ($context->isRecipientSideOfWish($wish)) {
            throw new LogicException('Recipient-side viewers have no access to reservations.');
        }
    }

    /**
     * Race guard for transactions that create a reservation or contribution. Takes a shared lock on the owner row,
     * which designating a manager (WishlistManagersController::sync) holds exclusively, then re-reads the manager
     * table: either the designation committed first and this aborts with 403, or this commits first and the purge
     * job that follows the designation sees the new reservation. Must run inside the write transaction.
     */
    public function assertStillGiver(User $giver, int $ownerId): void
    {
        User::query()->whereKey($ownerId)->sharedLock()->value('id');
        abort_if($ownerId === $giver->id || WishlistManager::query()->where('owner_id', $ownerId)->where('manager_id', $giver->id)->exists(), 403);
    }

    /**
     * Wish-based race guard. Group wish: shared lock on the group row (which every membership and mode change holds
     * exclusively), then re-read membership; 403 for a gifts-mode member or a shared-mode non-member.
     */
    public function assertStillGiverOfWish(User $giver, Wish $wish): void
    {
        if ($wish->group_id === null) {
            $this->assertStillGiver($giver, (int) $wish->owner_id);

            return;
        }

        $group = WishlistGroup::query()->whereKey($wish->group_id)->sharedLock()->first();
        abort_if($group === null, 404);
        $member = DB::table('wishlist_group_members')->where('group_id', $group->id)->where('user_id', $giver->id)->exists();
        abort_if(($member && $group->mode === GroupMode::Gifts) || (! $member && $group->mode === GroupMode::Shared), 403);
    }

    /** HTTP entry: 403 for recipient-side callers before any reservation table is touched. */
    public function authorize(User $viewer, Wish $wish): ViewerContext
    {
        $context = ViewerContext::for($viewer);
        if ($wish->group_id !== null) {
            abort_if($context->isRecipientSideOfWish($wish), 403);
            $group = WishlistGroup::query()->find($wish->group_id);
            abort_if($group === null || ! $this->groups->canView($viewer, $group), 403);

            return $context;
        }
        abort_if($context->isRecipientSideOf($wish->owner_id), 403);
        abort_unless(in_array($wish->owner_id, $this->members->ids($viewer), true), 403);

        return $context;
    }
}
