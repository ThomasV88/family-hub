<?php

namespace App\Wishlist;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;

/** Record-level wishlist rights. There is no admin bypass: admin status grants nothing here. */
final class WishAccess
{
    public function __construct(private readonly AccessGate $gate) {}

    public function canView(User $user): bool
    {
        return $this->gate->allows($user, Module::Wishlist, AccessLevel::View);
    }

    /** The owner or a designated manager, and only with the wishlist edit grant. */
    public function canEditList(User $user, int $ownerId): bool
    {
        if (! $this->gate->allows($user, Module::Wishlist, AccessLevel::Edit)) {
            return false;
        }

        return ViewerContext::for($user)->isRecipientSideOf($ownerId);
    }
}
