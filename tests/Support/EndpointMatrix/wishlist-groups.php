<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\WishlistGroup;

// A fresh group per call with the parent as its only member.
$parentGroup = fn (array $users) => ['group' => WishlistGroup::factory()->withMembers([$users['parent']])->create()->id];

$parentOnly = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'wishlist.groups.store' => [
        'method' => 'POST',
        'uri' => '/wishlist/groups',
        'data' => ['name' => 'Matrix list', 'mode' => 'gifts', 'member_ids' => []],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.groups.update' => [
        'method' => 'PATCH',
        'uri' => '/wishlist/groups/{group}',
        'params' => $parentGroup,
        'data' => ['name' => 'Renamed'],
        'expect' => $parentOnly,
    ],
    'wishlist.groups.members.update' => [
        'method' => 'PUT',
        'uri' => '/wishlist/groups/{group}/members',
        'params' => $parentGroup,
        'data' => fn (array $users) => ['member_ids' => [$users['parent']->id]],
        'expect' => $parentOnly,
    ],
    'wishlist.groups.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/groups/{group}',
        'params' => $parentGroup,
        'expect' => $parentOnly,
    ],
    // A second member keeps the parent from being the last one.
    'wishlist.groups.leave' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/groups/{group}/membership',
        'params' => fn (array $users) => ['group' => WishlistGroup::factory()
            ->withMembers([$users['parent'], User::factory()->withPreset(Preset::Parent)->create()])->create()->id],
        'expect' => $parentOnly,
    ],
];
