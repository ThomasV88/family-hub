<?php

namespace App\Http\Requests\Shopping;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadShoppingImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Module access is checked by the route middleware. An unknown id is a 404 before the body is read.
        $exists = $this->isCatalog()
            ? ShoppingCatalogItem::query()->whereKey((int) $this->route('item'))->exists()
            : ShoppingStore::query()->whereKey((int) $this->route('store'))->exists();
        if (! $exists) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bytes = (int) config($this->isCatalog() ? 'shopping.catalog_image_max_bytes' : 'shopping.store_logo_max_bytes');

        return ['image' => ['required', 'file', 'max:'.(int) ceil($bytes / 1024)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'shopping.image.err_type',
            'image.file' => 'shopping.image.err_type',
            'image.uploaded' => 'shopping.image.err_size',
            'image.max' => 'shopping.image.err_size',
        ];
    }

    public function isCatalog(): bool
    {
        return $this->route('item') !== null;
    }
}
