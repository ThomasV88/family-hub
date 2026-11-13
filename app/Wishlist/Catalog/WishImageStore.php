<?php

namespace App\Wishlist\Catalog;

use App\Models\Wish;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private storage for processed wish images on the local disk. No public disk, no URL generation. */
final class WishImageStore
{
    public function put(Wish $wish, ProcessedImage $image, string $source, ?string $host): Wish
    {
        $disk = Storage::disk('local');
        $name = strtolower((string) Str::ulid());
        $dir = 'wish-images/'.$wish->id;
        $full = "{$dir}/{$name}-full.jpg";
        $thumb = "{$dir}/{$name}-thumb.jpg";
        $oldPaths = array_filter([$wish->image_path, $wish->thumb_path]);

        // The disk is configured with throw=false, so a full disk or a bad volume owner returns false.
        // Nothing is touched (row, old files) unless both writes are confirmed.
        try {
            $stored = $disk->put($full, $image->fullBytes) !== false && $disk->put($thumb, $image->thumbBytes) !== false;
        } catch (\Throwable) {
            $stored = false;
        }
        if (! $stored) {
            $disk->delete([$full, $thumb]);
            throw new WishImageWriteFailed('wish image write failed');
        }

        $wish->forceFill([
            'image_path' => $full,
            'thumb_path' => $thumb,
            'image_source' => $source,
            'image_source_host' => $host,
            'image_status' => 'ready',
        ])->save();

        $this->deleteFiles($oldPaths);

        return $wish;
    }

    public function remove(Wish $wish): void
    {
        $this->deleteFiles(array_filter([$wish->image_path, $wish->thumb_path]));
        if ($wish->exists) {
            $wish->forceFill([
                'image_path' => null,
                'thumb_path' => null,
                'image_source' => null,
                'image_source_host' => null,
                'image_status' => 'none',
            ])->save();
        }
    }

    /** Deletes files under wish-images that no wish row references and that are older than the cutoff. */
    public function sweepOrphans(int $olderThanMinutes): int
    {
        $disk = Storage::disk('local');
        $referenced = Wish::query()->whereNotNull('image_path')->get(['image_path', 'thumb_path'])
            ->flatMap(fn (Wish $w) => [$w->image_path, $w->thumb_path])->filter()->flip();
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        $deleted = 0;
        foreach ($disk->allFiles('wish-images') as $path) {
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
