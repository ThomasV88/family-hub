<?php

namespace App\Profile;

use App\Models\User;
use App\Wishlist\ViewerContext;

/** Who may change the name and photo of whom. A person who can sign in edits only themselves. */
final class ProfileAccess
{
    public function canEdit(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }
        // Only a child (an account that cannot sign in) is managed by someone else.
        if ($target->canSignIn()) {
            return false;
        }
        if ($actor->is_admin) {
            return true;
        }

        // A designated wishlist manager row alone is enough; no Wishlist Edit grant is needed.
        return ViewerContext::for($actor)->isManagerOf($target->id);
    }
}
