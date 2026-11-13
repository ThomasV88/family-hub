<?php

namespace App\Wishlist\Giving;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued so the admin request is identical whether or not the new manager ever reserved anything. */
class PurgeManagerReservations implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $ownerId, public readonly int $managerId) {}

    public function handle(ManagerReservationPurger $purger): void
    {
        $purger->purge($this->ownerId, $this->managerId);
    }
}
