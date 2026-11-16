<?php

namespace App\Profile;

use App\Access\AccessGate;
use App\Models\User;
use App\Wishlist\ViewerContext;

/** Who may fetch the photo bytes of a member. */
final class ProfilePhotoAccess
{
    public function __construct(private readonly AccessGate $gate) {}

    public function canView(User $viewer, User $subject): bool
    {
        if ($viewer->id === $subject->id || $viewer->is_admin) {
            return true;
        }
        if (ViewerContext::for($viewer)->isManagerOf($subject->id)) {
            return true;
        }

        // Anyone who can see at least one module sees the family's names, so they see the pictures too.
        foreach ($this->gate->levels($viewer) as $label) {
            if ($label !== 'none') {
                return true;
            }
        }

        return false;
    }
}
