<?php

namespace App\Wishlist\Occasions;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\WishOccasion;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishlistMembers;

/**
 * Who maintains which occasion data. Always on top of the wishlist edit grant:
 * dates of member M by M, a designated manager of M or an admin; a custom occasion by its creator or an admin.
 */
final class OccasionAccess
{
    public function __construct(private readonly AccessGate $gate, private readonly WishlistMembers $members) {}

    public function canEditDates(User $actor, int $memberId): bool
    {
        if (! $this->gate->allows($actor, Module::Wishlist, AccessLevel::Edit)) {
            return false;
        }
        if (! in_array($memberId, $this->members->ids($actor), true)) {
            return false;
        }

        return $actor->id === $memberId
            || (bool) $actor->is_admin
            || ViewerContext::for($actor)->isManagerOf($memberId);
    }

    public function canEditOccasion(User $actor, WishOccasion $occasion): bool
    {
        return $occasion->kind === WishOccasion::CUSTOM
            && $this->gate->allows($actor, Module::Wishlist, AccessLevel::Edit)
            && ((bool) $actor->is_admin || $occasion->created_by_id === $actor->id);
    }

    /** A custom occasion the actor may change: 404 for missing ids and name-day rows, 403 for anyone else. */
    public function editableCustom(User $actor, int $id): WishOccasion
    {
        $occasion = WishOccasion::query()->where('kind', WishOccasion::CUSTOM)->findOrFail($id);
        abort_unless($this->canEditOccasion($actor, $occasion), 403);

        return $occasion;
    }
}
