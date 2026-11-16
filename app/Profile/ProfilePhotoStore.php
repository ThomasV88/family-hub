<?php

namespace App\Profile;

use App\Models\User;
use App\Wishlist\Catalog\ProcessedImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private storage for profile photos on the local disk. The prefix lives under wish-images/ on purpose: that is the
 * only path on the persisted, backed-up uploads volume. No public disk, no URL generation.
 */
final class ProfilePhotoStore
{
    public const PREFIX = 'wish-images/profile-photos';

    public function put(User $user, ProcessedImage $image): void
    {
        $disk = Storage::disk('local');
        $name = strtolower((string) Str::ulid());
        $dir = self::PREFIX.'/'.$user->id;
        $full = "{$dir}/{$name}-full.jpg";
        $thumb = "{$dir}/{$name}-thumb.jpg";
        $oldPaths = array_filter([$user->photo_path, $user->photo_thumb_path]);

        // The disk is configured with throw=false, so a full disk returns false. Nothing is touched
        // (row, old files) unless both writes are confirmed.
        try {
            $stored = $disk->put($full, $image->fullBytes) !== false && $disk->put($thumb, $image->thumbBytes) !== false;
        } catch (\Throwable) {
            $stored = false;
        }
        if (! $stored) {
            $disk->delete([$full, $thumb]);
            throw new ProfilePhotoWriteFailed('profile photo write failed');
        }

        $user->forceFill([
            'photo_path' => $full,
            'photo_thumb_path' => $thumb,
            'photo_version' => (int) $user->photo_version + 1,
        ])->save();

        $this->deleteFiles($oldPaths);
    }

    public function remove(User $user): void
    {
        $old = array_filter([$user->photo_path, $user->photo_thumb_path]);
        $user->forceFill([
            'photo_path' => null,
            'photo_thumb_path' => null,
            'photo_version' => (int) $user->photo_version + 1,
        ])->save();
        $this->deleteFiles($old);
    }

    /** Deletes profile photo files that no user row references and that are older than the cutoff. */
    public function sweepOrphans(int $olderThanMinutes): int
    {
        $disk = Storage::disk('local');
        $referenced = User::query()->whereNotNull('photo_path')->get(['id', 'photo_path', 'photo_thumb_path'])
            ->flatMap(fn (User $u) => [$u->photo_path, $u->photo_thumb_path])->filter()->flip();
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        $deleted = 0;
        foreach ($disk->allFiles(self::PREFIX) as $path) {
            if (! isset($referenced[$path]) && $disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param  iterable<string>  $paths */
    private function deleteFiles(iterable $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
