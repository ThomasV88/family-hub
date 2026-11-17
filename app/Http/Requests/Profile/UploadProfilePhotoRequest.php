<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Profile\ProfileAccess;
use Illuminate\Foundation\Http\FormRequest;

class UploadProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize before validating so someone who may not edit this person gets 403 whatever the body contains.
        $target = User::query()->findOrFail((int) $this->route('user'));

        return $this->user() !== null && app(ProfileAccess::class)->canEdit($this->user(), $target);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'max:'.(int) ceil(config('profile.photo_max_bytes') / 1024)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => 'profile.photo.err_decode',
            'photo.file' => 'profile.photo.err_type',
            'photo.uploaded' => 'profile.photo.err_size',
            'photo.max' => 'profile.photo.err_size',
        ];
    }
}
