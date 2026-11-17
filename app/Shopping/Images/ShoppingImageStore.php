<?php

namespace App\Shopping\Images;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ProcessedImage;
use App\Wishlist\Catalog\WishImageWriteFailed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private storage for catalog item pictures and store logos on the local disk, under wish-images/shopping/ (the
 * only path on the persisted, backed-up uploads volume). No public disk, no URL generation. Files are swapped
 * under a row lock with an image_version bump and old files are removed only after the commit.
 */
final class ShoppingImageStore
{
    public const PREFIX = 'wish-images/shopping';

    public const KIND_CATALOG = 'catalog';

    public const KIND_STORES = 'stores';

    public function storeCatalogImage(ShoppingCatalogItem $item, string $bytes): void
    {
        $image = app(ImageProcessor::class)->process(
            $bytes,
            (int) config('shopping.catalog_image_max_bytes'),
            (int) config('shopping.catalog_image_full_px'),
            (int) config('shopping.catalog_image_thumb_px'),
        );
        $this->put(self::KIND_CATALOG, $item, $image, 'image_path', 'thumb_path');
    }

    public function removeCatalogImage(ShoppingCatalogItem $item): void
    {
        $this->clear($item, 'image_path', 'thumb_path');
    }

    public function storeStoreLogo(ShoppingStore $store, string $bytes): void
    {
        $image = app(ImageProcessor::class)->process(
            $bytes,
            (int) config('shopping.store_logo_max_bytes'),
            (int) config('shopping.store_logo_full_px'),
            (int) config('shopping.store_logo_thumb_px'),
        );
        $this->put(self::KIND_STORES, $store, $image, 'logo_path', 'logo_thumb_path');
    }

    public function removeStoreLogo(ShoppingStore $store): void
    {
        $this->clear($store, 'logo_path', 'logo_thumb_path');
    }

    /** Removes every file of one row (call after the commit that deleted the row). */
    public function deleteDirectory(string $kind, int $id): void
    {
        if (! in_array($kind, [self::KIND_CATALOG, self::KIND_STORES], true)) {
            return;
        }
        Storage::disk('local')->deleteDirectory(self::PREFIX.'/'.$kind.'/'.$id);
    }

    /** Deletes shopping image files that no catalog or store row references and that are older than 60 minutes. */
    public function sweepOrphans(int $olderThanMinutes = 60): int
    {
        $disk = Storage::disk('local');
        $referenced = [];
        foreach (ShoppingCatalogItem::query()->whereNotNull('image_path')->get(['image_path', 'thumb_path']) as $row) {
            $referenced[$row->image_path] = true;
            $referenced[$row->thumb_path] = true;
        }
        foreach (ShoppingStore::query()->whereNotNull('logo_path')->get(['logo_path', 'logo_thumb_path']) as $row) {
            $referenced[$row->logo_path] = true;
            $referenced[$row->logo_thumb_path] = true;
        }
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

    private function put(string $kind, Model $model, ProcessedImage $image, string $fullCol, string $thumbCol): void
    {
        $disk = Storage::disk('local');
        $name = strtolower((string) Str::ulid());
        $dir = self::PREFIX.'/'.$kind.'/'.$model->getKey();
        $full = "{$dir}/{$name}-full.jpg";
        $thumb = "{$dir}/{$name}-thumb.jpg";

        // The disk is configured with throw=false: nothing is touched (row, old files) unless both writes are confirmed.
        try {
            $stored = $disk->put($full, $image->fullBytes) !== false && $disk->put($thumb, $image->thumbBytes) !== false;
        } catch (\Throwable) {
            $stored = false;
        }
        if (! $stored) {
            $disk->delete([$full, $thumb]);
            throw new WishImageWriteFailed('shopping image write failed');
        }

        $this->deleteFiles($this->swap($model, $full, $thumb, $fullCol, $thumbCol));
    }

    private function clear(Model $model, string $fullCol, string $thumbCol): void
    {
        $this->deleteFiles($this->swap($model, null, null, $fullCol, $thumbCol));
    }

    /**
     * Sets the image columns under a row lock and returns the paths they replaced (deleted only after the commit).
     *
     * @return array<int, string>
     */
    private function swap(Model $model, ?string $full, ?string $thumb, string $fullCol, string $thumbCol): array
    {
        return DB::transaction(function () use ($model, $full, $thumb, $fullCol, $thumbCol): array {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $old = array_values(array_filter([$locked->{$fullCol}, $locked->{$thumbCol}]));
            $locked->forceFill([
                $fullCol => $full,
                $thumbCol => $thumb,
                'image_version' => (int) $locked->image_version + 1,
            ])->save();
            $model->setRawAttributes($locked->getAttributes(), true);

            return $old;
        });
    }

    /** @param  iterable<string>  $paths */
    private function deleteFiles(iterable $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
