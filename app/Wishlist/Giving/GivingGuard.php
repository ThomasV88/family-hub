<?php

namespace App\Wishlist\Giving;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishlistMembers;
use LogicException;

/**
 * Fail-closed gate in front of everything that reads or writes reservations: the recipient (and a designated
 * manager) must never get past it, and must not be able to tell whether a reservation exists.
 */
final class GivingGuard
{
    public function __construct(private readonly WishlistMembers $members) {}

    /** Defense in depth for services and presenters: a routing mistake becomes an exception, never a leak. */
    public function assertGiver(ViewerContext $context, int $ownerId): void
    {
        if ($context->isRecipientSideOf($ownerId)) {
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

    /** HTTP entry: 403 for recipient-side callers before any reservation table is touched. */
    public function authorize(User $viewer, Wish $wish): ViewerContext
    {
        $context = ViewerContext::for($viewer);
        abort_if($context->isRecipientSideOf($wish->owner_id), 403);
        abort_unless(in_array($wish->owner_id, $this->members->ids($viewer), true), 403);

        return $context;
    }
}
