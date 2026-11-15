<?php

use App\Models\WishOccasion;

// Per-member dates: only the member themselves passes here (the parent row is the parent's own id).
$ownDates = fn (array $users) => ['member' => $users['parent']->id];
// A custom occasion created by the parent per call (destroy removes it).
$parentOccasion = fn (array $users) => [
    'occasion' => tap(WishOccasion::factory()->create(['title' => 'Matrix occasion']), fn (WishOccasion $o) => $o->forceFill(['created_by_id' => $users['parent']->id])->save())->id,
];

$creatorOnly = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'wishlist.occasions.index' => [
        'method' => 'GET',
        'uri' => '/wishlist/occasions',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.occasions.store' => [
        'method' => 'POST',
        'uri' => '/wishlist/occasions',
        'data' => ['title' => 'Matrix occasion', 'date' => '2027-06-01', 'yearly' => true],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303,
            'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.occasions.update' => [
        'method' => 'PATCH',
        'uri' => '/wishlist/occasions/{occasion}',
        'params' => $parentOccasion,
        'data' => ['title' => 'Changed', 'date' => '2027-06-02', 'yearly' => true],
        'expect' => $creatorOnly,
    ],
    'wishlist.occasions.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/occasions/{occasion}',
        'params' => $parentOccasion,
        'expect' => $creatorOnly,
    ],
    'wishlist.members.dates.update' => [
        'method' => 'PUT',
        'uri' => '/wishlist/members/{member}/dates',
        'params' => $ownDates,
        'data' => ['birth_date' => '1990-03-15'],
        'expect' => $creatorOnly,
    ],
    'wishlist.namedays.search' => [
        'method' => 'GET',
        'uri' => '/wishlist/namedays',
        'data' => ['calendar' => 'sk', 'q' => 'lub'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
