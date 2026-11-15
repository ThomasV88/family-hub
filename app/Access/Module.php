<?php

namespace App\Access;

enum Module: string
{
    case Todo = 'todo';
    case Wishlist = 'wishlist';
    case Finance = 'finance';
    case Health = 'health';

    /** Modules whose denied access and changes are written to the audit log. */
    public function audited(): bool
    {
        return in_array($this, [self::Finance, self::Health], true);
    }

    /** Modules behind the idle re-authentication gate. */
    public function sensitive(): bool
    {
        return in_array($this, [self::Finance, self::Health], true);
    }
}
