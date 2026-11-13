<?php

namespace App\Http\Requests\Wishlist;

use App\Models\Wish;
use App\Wishlist\WishAccess;
use Illuminate\Foundation\Http\FormRequest;

class UploadWishImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize before validating so a non-owner gets 403 whatever the body contains.
        $wish = Wish::query()->findOrFail((int) $this->route('wish'));

        return app(WishAccess::class)->canEditList($this->user(), $wish->owner_id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:'.config('wishlist.upload_max_kb')]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'wishlist.image.err_upload',
            'image.file' => 'wishlist.image.err_type',
            'image.uploaded' => 'wishlist.image.err_size',
            'image.max' => 'wishlist.image.err_size',
        ];
    }
}
