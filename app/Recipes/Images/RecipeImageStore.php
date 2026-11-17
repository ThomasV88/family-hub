<?php

namespace App\Recipes\Images;

use App\Models\Recipe;
use App\Models\RecipePhoto;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageWriteFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Private storage for recipe photos on the local disk (the persisted, backed-up uploads volume), under
 * wish-images/recipes/{recipe id}/. No public disk, no URL generation. Rows are changed under a lock on the recipe
 * row, files are removed only after the commit.
 */
class RecipeImageStore
{
    public const PREFIX = 'wish-images/recipes';

    public function directory(int $recipeId): string
    {
        return self::PREFIX.'/'.$recipeId;
    }

    /**
     * Re-encodes and stores one photo. The first photo of a recipe becomes the main one. Throws ImageRejected for
     * bad bytes, WishImageWriteFailed when the disk refuses, ValidationException (recipes.image.err_count) at the cap.
     */
    public function add(Recipe $recipe, UploadedFile|string $bytes): RecipePhoto
    {
        if ($bytes instanceof UploadedFile) {
            $content = $bytes->isValid() ? file_get_contents($bytes->getRealPath()) : false;
            if ($content === false) {
                throw new ImageRejected('unsupported_type');
            }
            $bytes = $content;
        }
        $image = app(ImageProcessor::class)->process(
            $bytes,
            (int) config('recipes.photos.max_bytes'),
            (int) config('recipes.photos.full_px'),
            (int) config('recipes.photos.thumb_px'),
        );

        $disk = Storage::disk('local');
        $name = strtolower((string) Str::ulid());
        $dir = $this->directory($recipe->id);
        $full = "{$dir}/{$name}-full.jpg";
        $thumb = "{$dir}/{$name}-thumb.jpg";

        // The disk is configured with throw=false: nothing is inserted unless both writes are confirmed.
        try {
            $stored = $disk->put($full, $image->fullBytes) !== false && $disk->put($thumb, $image->thumbBytes) !== false;
        } catch (\Throwable) {
            $stored = false;
        }
        if (! $stored) {
            $disk->delete([$full, $thumb]);
            throw new WishImageWriteFailed('recipe image write failed');
        }

        try {
            return DB::transaction(function () use ($recipe, $full, $thumb): RecipePhoto {
                Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
                $count = RecipePhoto::query()->where('recipe_id', $recipe->id)->count();
                if ($count >= (int) config('recipes.photos.max_count')) {
                    throw ValidationException::withMessages(['image' => 'recipes.image.err_count']);
                }
                $position = (int) RecipePhoto::query()->where('recipe_id', $recipe->id)->max('position') + 1;

                return RecipePhoto::query()->create([
                    'recipe_id' => $recipe->id,
                    'path' => $full,
                    'thumb_path' => $thumb,
                    'position' => $position,
                    'is_main' => $count === 0,
                    'version' => 1,
                ]);
            });
        } catch (\Throwable $e) {
            $disk->delete([$full, $thumb]);
            throw $e;
        }
    }

    public function makeMain(Recipe $recipe, RecipePhoto $photo): void
    {
        DB::transaction(function () use ($recipe, $photo): void {
            $this->lock($recipe);
            $target = RecipePhoto::query()->where('recipe_id', $recipe->id)->whereKey($photo->id)->firstOrFail();
            if ($target->is_main) {
                return;
            }
            // The partial unique index allows one main: clear first, then set.
            RecipePhoto::query()->where('recipe_id', $recipe->id)->where('is_main', true)->update(['is_main' => false]);
            $target->forceFill(['is_main' => true])->save();
        });
    }

    /** Swaps the photo with its neighbour; at the edge nothing changes. */
    public function move(Recipe $recipe, RecipePhoto $photo, string $direction): void
    {
        DB::transaction(function () use ($recipe, $photo, $direction): void {
            $this->lock($recipe);
            $rows = RecipePhoto::query()->where('recipe_id', $recipe->id)->orderBy('position')->orderBy('id')->get()->values();
            $index = $rows->search(fn (RecipePhoto $p) => $p->id === $photo->id);
            if ($index === false) {
                abort(404);
            }
            $other = $direction === 'earlier' ? $index - 1 : $index + 1;
            if ($other < 0 || $other >= $rows->count()) {
                return;
            }
            $order = $rows->all();
            [$order[$index], $order[$other]] = [$order[$other], $order[$index]];
            // Renumber every row so ties in position can never make a move a no-op.
            foreach ($order as $i => $row) {
                if ($row->position !== $i + 1) {
                    $row->forceFill(['position' => $i + 1])->save();
                }
            }
        });
    }

    /** Deletes the row, then the files after the commit; promotes the first remaining photo if the main one went. */
    public function remove(Recipe $recipe, RecipePhoto $photo): void
    {
        $paths = DB::transaction(function () use ($recipe, $photo): array {
            $this->lock($recipe);
            $target = RecipePhoto::query()->where('recipe_id', $recipe->id)->whereKey($photo->id)->firstOrFail();
            $wasMain = $target->is_main;
            $paths = [$target->path, $target->thumb_path];
            $target->delete();
            if ($wasMain) {
                $next = RecipePhoto::query()->where('recipe_id', $recipe->id)->orderBy('position')->orderBy('id')->first();
                $next?->forceFill(['is_main' => true])->save();
            }

            return $paths;
        });
        Storage::disk('local')->delete($paths);
    }

    /** Removes every photo file of one recipe (call after the commit that deleted the row). */
    public function deleteDirectory(int $recipeId): void
    {
        Storage::disk('local')->deleteDirectory($this->directory($recipeId));
    }

    /** Deletes recipe photo files that no row references and that are older than the threshold. */
    public function sweepOrphans(int $olderThanMinutes = 60): int
    {
        $disk = Storage::disk('local');
        $referenced = [];
        foreach (RecipePhoto::query()->get(['path', 'thumb_path']) as $row) {
            $referenced[$row->path] = true;
            $referenced[$row->thumb_path] = true;
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

    private function lock(Recipe $recipe): void
    {
        Recipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
    }
}
