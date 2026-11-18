<?php

use App\Models\Wish;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

$parentWish = fn (array $users) => ['wish' => Wish::factory()->create(['owner_id' => $users['parent']->id])->id];

$imageWish = function (array $users) {
    Storage::fake('local');
    $wish = Wish::factory()->withImage()->create(['owner_id' => $users['parent']->id]);
    Storage::disk('local')->put($wish->thumb_path, ImageFixtures::jpeg());

    return ['wish' => $wish->id, 'variant' => 'thumb'];
};

$ownerOnlyWrite = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'wishlist.images.show' => [
        'method' => 'GET',
        'uri' => '/wishlist/wishes/{wish}/image/{variant}',
        'params' => $imageWish,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    // The matrix posts no file, so the owner reaches validation and is redirected back (302) with an error;
    // everyone else is refused with 403 before validation. Real uploads are covered in WishImageTest.
    'wishlist.images.store' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes/{wish}/image',
        'params' => $parentWish,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.images.destroy' => [
        'method' => 'DELETE',
        'uri' => '/wishlist/wishes/{wish}/image',
        'params' => $parentWish,
        'expect' => $ownerOnlyWrite,
    ],
    'wishlist.images.fetch' => [
        'method' => 'POST',
        'uri' => '/wishlist/wishes/{wish}/image/fetch',
        'params' => $parentWish,
        'expect' => $ownerOnlyWrite,
    ],

    // Link preview (issue #16): needs wishlist edit access. The matrix posts no URL, so an allowed role is bounced back
    // with a validation error (302); a poll or thumbnail of an unknown token is a uniform 404 for allowed roles
    // (a token is bound to the user who asked); everyone else is refused with 403 before the controller runs.
    'wishlist.link-preview.start' => [
        'method' => 'POST',
        'uri' => '/wishlist/link-preview',
        'params' => fn () => [],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 302,
            'grandparent' => 302, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.link-preview.show' => [
        'method' => 'GET',
        'uri' => '/wishlist/link-preview/{token}',
        'params' => fn () => ['token' => str_repeat('a', 40)],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 404, 'child' => 404,
            'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'wishlist.link-preview.image' => [
        'method' => 'GET',
        'uri' => '/wishlist/link-preview/{token}/image',
        'params' => fn () => ['token' => str_repeat('a', 40)],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 404, 'child' => 404,
            'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
