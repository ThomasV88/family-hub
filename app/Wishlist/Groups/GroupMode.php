<?php

namespace App\Wishlist\Groups;

/** Gifts: the group is the recipient and reservations are hidden from its members. Shared: members see everything. */
enum GroupMode: string
{
    case Gifts = 'gifts';
    case Shared = 'shared';

    public function isHidden(): bool
    {
        return $this === self::Gifts;
    }
}
