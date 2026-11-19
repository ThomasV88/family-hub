<?php

namespace App\Wishlist\Giving;

use App\Models\WishContribution;
use App\Wishlist\Groups\GroupMode;
use App\Wishlist\Money;
use App\Wishlist\ViewerContext;

/** The viewer's own shopping list: their reservations and shares, never on lists where they are recipient-side. */
final class ShoppingSummary
{
    /** @return array{groups: list<array{owner: array{id: int|null, name: string}, group?: array{id: int, name: string}, items: list<array<string, mixed>>}>} */
    public function forViewer(ViewerContext $context): array
    {
        $viewer = $context->viewer();

        $rows = WishContribution::query()
            ->join('wish_reservations', 'wish_reservations.id', '=', 'wish_contributions.reservation_id')
            ->join('wishes', 'wishes.id', '=', 'wish_reservations.wish_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'wishes.owner_id')
            ->leftJoin('wishlist_groups', 'wishlist_groups.id', '=', 'wishes.group_id')
            ->where('wish_contributions.user_id', $viewer->id)
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->whereNotNull('wishes.owner_id')->whereNotIn('wishes.owner_id', $context->recipientSideOwnerIds()))
                ->orWhere(fn ($g) => $g->whereNotNull('wishes.group_id')
                    ->whereNotIn('wishes.group_id', $context->recipientSideGroupIds())
                    ->where(fn ($v) => $v->where('wishlist_groups.mode', GroupMode::Gifts->value)
                        ->orWhereIn('wishes.group_id', fn ($m) => $m->select('group_id')->from('wishlist_group_members')->where('user_id', $viewer->id)))))
            ->whereNull('wishes.received_at')
            ->orderByRaw('wishes.group_id is not null')
            ->orderByRaw('coalesce(owners.name, wishlist_groups.name)')
            ->orderByRaw('coalesce(owners.id, wishlist_groups.id)')
            ->orderBy('wishes.title')->orderBy('wishes.id')
            ->get([
                'owners.id as owner_id', 'owners.name as owner_name',
                'wishlist_groups.id as group_id', 'wishlist_groups.name as group_name',
                'wishes.id as wish_id', 'wishes.title', 'wishes.price_minor', 'wishes.price_currency',
                'wish_reservations.cobuy_state', 'wish_reservations.user_id as organizer_id',
                'wish_contributions.amount_minor',
            ]);

        $groups = [];
        foreach ($rows as $row) {
            if ($row->group_id !== null) {
                $key = 'g'.$row->group_id;
                $groups[$key] ??= [
                    'owner' => ['id' => null, 'name' => $row->group_name],
                    'group' => ['id' => (int) $row->group_id, 'name' => $row->group_name],
                    'items' => [],
                ];
            } else {
                $key = 'u'.$row->owner_id;
                $groups[$key] ??= ['owner' => ['id' => (int) $row->owner_id, 'name' => $row->owner_name], 'items' => []];
            }
            $groups[$key]['items'][] = [
                'wish_id' => (int) $row->wish_id,
                'title' => $row->title,
                'price' => Money::toDecimal($row->price_minor === null ? null : (int) $row->price_minor),
                'currency' => $row->price_currency,
                'cobuy_state' => $row->cobuy_state,
                'is_organizer' => (int) $row->organizer_id === $viewer->id,
                'my_amount' => Money::toDecimal($row->amount_minor === null ? null : (int) $row->amount_minor),
            ];
        }

        return ['groups' => array_values($groups)];
    }
}
