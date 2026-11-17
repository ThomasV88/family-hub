<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\UploadShoppingImageRequest;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Shopping\Images\ShoppingImageStore;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageWriteFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Catalog item pictures and store logos: private files, edit to change, view to see. */
class ImageController extends Controller
{
    public function storeCatalog(UploadShoppingImageRequest $request, int $item, ShoppingImageStore $store): RedirectResponse
    {
        $model = ShoppingCatalogItem::query()->findOrFail($item);
        $this->upload($request, fn (string $bytes) => $store->storeCatalogImage($model, $bytes));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.image_saved']);
    }

    public function storeStore(UploadShoppingImageRequest $request, int $store, ShoppingImageStore $images): RedirectResponse
    {
        $model = ShoppingStore::query()->findOrFail($store);
        $this->upload($request, fn (string $bytes) => $images->storeStoreLogo($model, $bytes));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.image_saved']);
    }

    public function destroyCatalog(int $item, ShoppingImageStore $store): RedirectResponse
    {
        $store->removeCatalogImage(ShoppingCatalogItem::query()->findOrFail($item));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.image_removed']);
    }

    public function destroyStore(int $store, ShoppingImageStore $images): RedirectResponse
    {
        $images->removeStoreLogo(ShoppingStore::query()->findOrFail($store));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.image_removed']);
    }

    public function showCatalog(Request $request, int $item, string $variant): Response
    {
        $row = ShoppingCatalogItem::query()->find($item);

        return $this->stream($request, $row === null ? null : ($variant === 'thumb' ? $row->thumb_path : $row->image_path));
    }

    public function showStore(Request $request, int $store, string $variant): Response
    {
        $row = ShoppingStore::query()->find($store);

        return $this->stream($request, $row === null ? null : ($variant === 'thumb' ? $row->logo_thumb_path : $row->logo_path));
    }

    /** Revalidated on every view (no-cache + ETag). Every miss is the same 404. */
    private function stream(Request $request, ?string $path): Response
    {
        $disk = Storage::disk('local');
        abort_if($path === null || ! $disk->exists($path), 404);

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

    private function upload(UploadShoppingImageRequest $request, callable $save): void
    {
        $file = $request->file('image');
        $bytes = $file === null || ! $file->isValid() ? false : file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['image' => 'shopping.image.err_type']);
        }

        try {
            $save($bytes);
        } catch (ImageRejected $e) {
            $key = match ($e->reason) {
                'too_large' => 'shopping.image.err_size',
                'too_many_pixels' => 'shopping.image.err_pixels',
                'busy' => 'shopping.image.err_write',
                default => 'shopping.image.err_type',
            };
            throw ValidationException::withMessages(['image' => $key]);
        } catch (WishImageWriteFailed) {
            throw ValidationException::withMessages(['image' => 'shopping.image.err_write']);
        }
    }
}
