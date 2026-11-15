<?php

use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;

// A fresh wish on the child's list per call (a parent or grandparent is the giver; the child owns it).
$childWish = fn (array $users) => ['wish' => Wish::factory()->create(['owner_id' => $users['child']->id])->id];

// The parent is the organizer, so only the parent may cancel; other givers get 403.
$reservedByParent = function (array $users) {
    $wish = Wish::factory()->create(['owner_id' => $users['child']->id]);
    WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $users['parent']->id]);

    return ['wish' => $wish->id];
};

// A reservation by the parent on a child's wish; the grandparent already has a share when co-buy is open.
$reservedBy = function (array $users, string $state) {
    $wish = Wish::factory()->create(['owner_id' => $users['child']->id]);
    $reservation = WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $users['parent']->id, 'cobuy_state' => $state]);
    if ($state === 'open') {
        WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => $users['grandparent']->id, 'amount_minor' => 500]);
    }

    return ['wish' => $wish->id];
};

$organizerOnly = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'wishlist.giving.reserve' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes/{wish}/reservation',
        'params' => $childWish,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.giving.cancel' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}/reservation',
        'params' => $reservedByParent,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.giving.cobuy.open' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes/{wish}/reservation/cobuy',
        'params' => fn (array $users) => $reservedBy($users, 'none'),
        'expect' => $organizerOnly,
    ],
    'wishlist.giving.cobuy.close' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}/reservation/cobuy',
        'params' => fn (array $users) => $reservedBy($users, 'open'),
        'expect' => $organizerOnly,
    ],
    'wishlist.giving.contribution.update' => [
        'method' => 'PUT',
        'uri' => '/wishlist/wishes/{wish}/reservation/contribution',
        'params' => fn (array $users) => $reservedBy($users, 'open'),
        'data' => ['amount' => '12,50'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.giving.contribution.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}/reservation/contribution',
        'params' => fn (array $users) => $reservedBy($users, 'open'),
        // The organizer cannot leave their own reservation (409); the joined grandparent can.
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 409, 'child' => 403,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    // JSON endpoint; guests are redirected to login like every other route in this matrix.
    'wishlist.giving.shopping' => [
        'method' => 'GET',
        'uri' => '/wishlist/shopping',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
