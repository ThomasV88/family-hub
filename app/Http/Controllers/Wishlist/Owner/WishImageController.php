<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\UploadWishImageRequest;
use App\Models\Wish;
use App\Wishlist\Catalog\FetchWishImage;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageStore;
use App\Wishlist\WishAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WishImageController extends Controller
{
    /** Streams a private file. Revalidated on every view (no-cache + ETag), so access is re-checked each time. */
    public function show(Request $request, int $wish, string $variant): Response
    {
        $model = Wish::query()->findOrFail($wish);
        $path = $variant === 'thumb' ? $model->thumb_path : ($variant === 'full' ? $model->image_path : null);
        $disk = Storage::disk('local');
        abort_if($path === null || $model->image_status !== 'ready' || ! $disk->exists($path), 404);

        $response = response($disk->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.sha1($path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Cookie',
        ]);
        $response->isNotModified($request);

        return $response;
    }

    public function store(UploadWishImageRequest $request, int $wish, ImageProcessor $processor, WishImageStore $store, WishAccess $access): RedirectResponse
    {
        $model = $this->editable($request, $wish, $access);

        $file = $request->file('image');
        $bytes = $file === null || ! $file->isValid() ? false : file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['image' => 'wishlist.image.err_upload']);
        }

        try {
            $processed = $processor->process($bytes, 5 * 1024 * 1024);
        } catch (ImageRejected $e) {
            $key = match ($e->reason) {
                'too_large', 'too_many_pixels' => 'wishlist.image.err_size',
                default => 'wishlist.image.err_type',
            };
            throw ValidationException::withMessages(['image' => $key]);
        }
        $store->put($model, $processed, 'uploaded', null);

        return back(303)->with('toast', ['key' => 'wishlist.toast.saved']);
    }

    public function destroy(Request $request, int $wish, WishImageStore $store, WishAccess $access): RedirectResponse
    {
        $store->remove($this->editable($request, $wish, $access));

        return back(303)->with('toast', ['key' => 'wishlist.toast.image_removed']);
    }

    public function fetch(Request $request, int $wish, WishAccess $access): RedirectResponse
    {
        $model = $this->editable($request, $wish, $access);
        if ($model->urls()->doesntExist()) {
            return back(303);
        }
        $model->forceFill(['image_status' => 'pending'])->save();
        FetchWishImage::dispatch($model->id);

        return back(303);
    }

    private function editable(Request $request, int $wish, WishAccess $access): Wish
    {
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditList($request->user(), $model->owner_id), 403);

        return $model;
    }
}
