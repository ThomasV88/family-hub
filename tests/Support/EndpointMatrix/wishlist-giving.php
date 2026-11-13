<?php

use App\Models\Wish;
use App\Models\WishReservation;

// A fresh wish on the child's list per call (a parent or grandparent is the giver; the child owns it).
$childWish = fn (array $users) => ['wish' => Wish::factory()->create(['owner_id' => $users['child']->id])->id];

// The parent is the organizer, so only the parent may cancel; other givers get 403.
$reservedByParent = function (array $users) {
    $wish = Wish::factory()->create(['owner_id' => $users['child']->id]);
    WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $users['parent']->id]);

    return ['wish' => $wish->id];
};

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
];
