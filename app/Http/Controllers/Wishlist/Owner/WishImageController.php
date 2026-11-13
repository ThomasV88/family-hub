<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\UploadWishImageRequest;
use App\Models\Wish;
use App\Wishlist\Catalog\FetchWishImage;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageStore;
use App\Wishlist\Catalog\WishImageWriteFailed;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishAccess;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WishImageController extends Controller
{
    /** Streams a private file. Revalidated on every view (no-cache + ETag), so access is re-checked each time. */
    public function show(Request $request, int $wish, string $variant, WishlistMembers $members): Response
    {
        $model = Wish::query()->find($wish);
        // Same visibility as the list: owner must be a visible list for this viewer, and received wishes are
        // recipient-side only. Every refusal is the same 404 as an unknown id or a missing image.
        $viewer = $request->user();
        abort_if($model === null
            || ! in_array($model->owner_id, $members->ids($viewer), true)
            || ($model->received_at !== null && ! ViewerContext::for($viewer)->isRecipientSideOf($model->owner_id)), 404);
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
        try {
            $store->put($model, $processed, 'uploaded', null);
        } catch (WishImageWriteFailed) {
            throw ValidationException::withMessages(['image' => 'wishlist.image.err_upload']);
        }

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
        // An uploaded image is never replaced by an automatic one, and must stay visible (not pending).
        abort_if($model->image_source === 'uploaded', 409);
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
