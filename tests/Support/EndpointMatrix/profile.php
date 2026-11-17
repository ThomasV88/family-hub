<?php

use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

// The target is the parent member. Only the parent (self) may change their own photo; admin and everyone else
// get 403 because an account that can sign in is never edited by someone else.
$parentTarget = fn (array $users) => ['user' => $users['parent']->id];

$parentWithPhoto = function (array $users) {
    Storage::fake('local');
    $parent = $users['parent'];
    $parent->forceFill([
        'photo_path' => "wish-images/profile-photos/{$parent->id}/a-full.jpg",
        'photo_thumb_path' => "wish-images/profile-photos/{$parent->id}/a-thumb.jpg",
    ])->save();
    Storage::disk('local')->put($parent->photo_thumb_path, ImageFixtures::jpeg());

    return ['user' => $parent->id, 'variant' => 'thumb'];
};

return [
    'profile.photo.show' => [
        'method' => 'GET',
        'uri' => '/people/{user}/photo/{variant}',
        'params' => $parentWithPhoto,
        // Anyone with View on at least one module (the guest preset has todo View) and the admin see it;
        // a signed-in user without any grant gets the same 404 as a missing photo.
        'expect' => [
            'guest' => 302, 'admin' => 200, 'parent' => 200, 'child' => 200,
            'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 404,
        ],
    ],
    // The matrix posts no file, so the parent reaches validation and is redirected back (302) with an error;
    // everyone else is refused with 403 before validation. Real uploads are covered in ProfilePhotoTest.
    'profile.photo.store' => [
        'method' => 'POST',
        'uri' => '/people/{user}/photo',
        'params' => $parentTarget,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'profile.photo.destroy' => [
        'method' => 'DELETE',
        'uri' => '/people/{user}/photo',
        'params' => $parentTarget,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
