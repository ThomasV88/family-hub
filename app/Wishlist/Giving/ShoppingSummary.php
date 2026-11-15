<?php

namespace App\Wishlist\Giving;

use App\Models\WishContribution;
use App\Wishlist\Money;
use App\Wishlist\ViewerContext;

/** The viewer's own shopping list: their reservations and shares, never on lists where they are recipient-side. */
final class ShoppingSummary
{
    /** @return array{groups: list<array{owner: array{id: int, name: string}, items: list<array<string, mixed>>}>} */
    public function forViewer(ViewerContext $context): array
    {
        $viewer = $context->viewer();

        $rows = WishContribution::query()
            ->join('wish_reservations', 'wish_reservations.id', '=', 'wish_contributions.reservation_id')
            ->join('wishes', 'wishes.id', '=', 'wish_reservations.wish_id')
            ->join('users as owners', 'owners.id', '=', 'wishes.owner_id')
            ->where('wish_contributions.user_id', $viewer->id)
            ->whereNotIn('wishes.owner_id', $context->recipientSideOwnerIds())
            ->whereNull('wishes.received_at')
            ->orderBy('owners.name')->orderBy('owners.id')->orderBy('wishes.title')->orderBy('wishes.id')
            ->get([
                'owners.id as owner_id', 'owners.name as owner_name',
                'wishes.id as wish_id', 'wishes.title', 'wishes.price_minor', 'wishes.price_currency',
                'wish_reservations.cobuy_state', 'wish_reservations.user_id as organizer_id',
                'wish_contributions.amount_minor',
            ]);

        $groups = [];
        foreach ($rows as $row) {
            $ownerId = (int) $row->owner_id;
            $groups[$ownerId] ??= ['owner' => ['id' => $ownerId, 'name' => $row->owner_name], 'items' => []];
            $groups[$ownerId]['items'][] = [
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
