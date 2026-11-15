<?php

use App\Models\Wish;

// A fresh wish on the parent's list per call (destroy removes it).
$parentWish = fn (array $users) => ['wish' => Wish::factory()->create(['owner_id' => $users['parent']->id])->id];

$ownerOnlyWrite = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'wishlist.index' => [
        'method' => 'GET',
        'uri' => '/wishlist',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.wishes.store' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes',
        'data' => ['title' => 'Matrix wish'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.wishes.update' => [
        'method' => 'PATCH',
        'uri' => '/wishlist/wishes/{wish}',
        'params' => $parentWish,
        'data' => ['title' => 'Changed'],
        'expect' => $ownerOnlyWrite,
    ],
    'wishlist.wishes.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}',
        'params' => $parentWish,
        'expect' => $ownerOnlyWrite,
    ],
    'wishlist.wishes.received.store' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes/{wish}/received',
        'params' => $parentWish,
        'expect' => $ownerOnlyWrite,
    ],
    'wishlist.wishes.received.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}/received',
        'params' => $parentWish,
        'expect' => $ownerOnlyWrite,
    ],
];
