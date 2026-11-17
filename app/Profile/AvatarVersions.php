<?php

namespace App\Profile;

use App\Models\User;

/** The small map of members that have a photo: user id => photo version. Never paths or file names. */
final class AvatarVersions
{
    /** @return array<string, int> */
    public static function map(): array
    {
        $map = [];
        foreach (User::query()->whereNotNull('photo_path')->get(['id', 'photo_version']) as $row) {
            $map[(string) $row->id] = (int) $row->photo_version;
        }

        return $map;
    }
}
