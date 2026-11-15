<?php

namespace App\Wishlist;

use App\Models\User;
use App\Models\WishlistManager;

/**
 * Who counts as the recipient of which list. Owner and designated manager only: an edit grant or the admin
 * role never makes a viewer recipient-side. Reservation-blind by design (arch-tested).
 */
final class ViewerContext
{
    /** @param  list<int>  $managedOwnerIds */
    private function __construct(private readonly User $viewer, private readonly array $managedOwnerIds) {}

    public static function for(User $viewer): self
    {
        $ids = WishlistManager::query()
            ->where('manager_id', $viewer->id)
            ->orderBy('owner_id')
            ->pluck('owner_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self($viewer, $ids);
    }

    public function viewer(): User
    {
        return $this->viewer;
    }

    public function isManagerOf(int $ownerId): bool
    {
        return $ownerId !== $this->viewer->id && in_array($ownerId, $this->managedOwnerIds, true);
    }

    public function isRecipientSideOf(int $ownerId): bool
    {
        return $ownerId === $this->viewer->id || $this->isManagerOf($ownerId);
    }

    /** @return list<int> */
    public function recipientSideOwnerIds(): array
    {
        return [$this->viewer->id, ...$this->managedOwnerIds];
    }
}
