<?php

namespace App\Wishlist;

use App\Models\User;
use App\Models\Wish;
use App\Wishlist\Groups\GroupMode;
use Illuminate\Support\Facades\DB;

/**
 * Who counts as the recipient of which list. Owner and designated manager, plus members of a gifts-mode group
 * list: an edit grant or the admin role never makes a viewer recipient-side. Reservation-blind by design (arch-tested).
 */
final class ViewerContext
{
    /**
     * @param  list<int>  $managedOwnerIds
     * @param  array<int, GroupMode>  $groupModes  group id => mode for the viewer's memberships
     */
    private function __construct(private readonly User $viewer, private readonly array $managedOwnerIds, private readonly array $groupModes = []) {}

    public static function for(User $viewer): self
    {
        // One query for managers and group memberships together (kind 'm' = managed owner, 'g' = group id with mode).
        $managers = DB::table('wishlist_managers')
            ->where('manager_id', $viewer->id)
            ->selectRaw("'m' as kind, owner_id as ref, null as mode");
        $rows = DB::table('wishlist_group_members')
            ->join('wishlist_groups', 'wishlist_groups.id', '=', 'wishlist_group_members.group_id')
            ->where('wishlist_group_members.user_id', $viewer->id)
            ->selectRaw("'g' as kind, wishlist_groups.id as ref, wishlist_groups.mode as mode")
            ->unionAll($managers)
            ->orderBy('kind')
            ->orderBy('ref')
            ->get();

        $ids = [];
        $groupModes = [];
        foreach ($rows as $row) {
            if ($row->kind === 'm') {
                $ids[] = (int) $row->ref;
            } else {
                $groupModes[(int) $row->ref] = GroupMode::from($row->mode);
            }
        }

        return new self($viewer, $ids, $groupModes);
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

    public function isGroupMember(int $groupId): bool
    {
        return isset($this->groupModes[$groupId]);
    }

    public function isRecipientSideOfGroup(int $groupId): bool
    {
        return ($this->groupModes[$groupId] ?? null) === GroupMode::Gifts;
    }

    /** @return list<int> gifts-mode groups the viewer belongs to */
    public function recipientSideGroupIds(): array
    {
        return array_values(array_map('intval', array_keys(array_filter($this->groupModes, fn (GroupMode $m) => $m === GroupMode::Gifts))));
    }

    public function isRecipientSideOfWish(Wish $wish): bool
    {
        return $wish->group_id !== null
            ? $this->isRecipientSideOfGroup((int) $wish->group_id)
            : $this->isRecipientSideOf((int) $wish->owner_id);
    }
}
