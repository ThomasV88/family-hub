<?php

namespace App\Wishlist\Giving;

use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use Illuminate\Support\Facades\DB;

/**
 * Keeps reservations consistent with a group list's mode and membership. Called inside the caller's
 * transaction for mode and membership changes; purgeAll is the daily defense-in-depth sweep.
 */
final class GroupReservationPurger
{
    /** Every reservation (and so every contribution) on the group's wishes. */
    public function purgeGroup(int $groupId): void
    {
        WishReservation::query()->whereIn('wish_id', Wish::query()->where('group_id', $groupId)->select('id'))->delete();
    }

    /** What one person reserved on the group's wishes, and their shares in other people's reservations there. */
    public function purgeMember(int $groupId, int $userId): void
    {
        $wishIds = Wish::query()->where('group_id', $groupId)->select('id');

        WishReservation::query()->where('user_id', $userId)->whereIn('wish_id', $wishIds)->delete();

        $reservationIds = WishReservation::query()->whereIn('wish_id', $wishIds)->select('id');
        WishContribution::query()->where('user_id', $userId)->whereIn('reservation_id', $reservationIds)->delete();
    }

    /** Members' holdings on gifts groups and non-members' holdings on shared groups. */
    public function purgeAll(): void
    {
        $gifts = DB::table('wishlist_groups')->where('mode', 'gifts')->select('id');
        $shared = DB::table('wishlist_groups')->where('mode', 'shared')->select('id');
        $memberOf = fn ($q) => $q->select(DB::raw('1'))->from('wishlist_group_members as m')
            ->whereColumn('m.group_id', 'wishes.group_id')->whereColumn('m.user_id', 'wish_reservations.user_id');

        // Reservations by members of a gifts group on that group's wishes (contributions cascade).
        $reservationQuery = fn () => WishReservation::query()
            ->join('wishes', 'wishes.id', '=', 'wish_reservations.wish_id')
            ->select('wish_reservations.id');

        WishReservation::query()->whereIn('id', $reservationQuery()->whereIn('wishes.group_id', $gifts)
            ->whereExists($memberOf))->delete();
        WishReservation::query()->whereIn('id', $reservationQuery()->whereIn('wishes.group_id', $shared)
            ->whereNotExists($memberOf))->delete();

        $contribution = fn () => WishContribution::query()
            ->join('wish_reservations', 'wish_reservations.id', '=', 'wish_contributions.reservation_id')
            ->join('wishes', 'wishes.id', '=', 'wish_reservations.wish_id')
            ->select('wish_contributions.id');
        $contribMember = fn ($q) => $q->select(DB::raw('1'))->from('wishlist_group_members as m')
            ->whereColumn('m.group_id', 'wishes.group_id')->whereColumn('m.user_id', 'wish_contributions.user_id');

        WishContribution::query()->whereIn('id', $contribution()->whereIn('wishes.group_id', $gifts)
            ->whereExists($contribMember))->delete();
        WishContribution::query()->whereIn('id', $contribution()->whereIn('wishes.group_id', $shared)
            ->whereNotExists($contribMember))->delete();
    }
}
