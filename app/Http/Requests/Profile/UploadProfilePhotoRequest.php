<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Profile\ProfileAccess;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorize before validating. An unknown id and a person the caller may not edit are the same 404
        // (like the view routes), so ids cannot be enumerated by status code.
        $target = User::query()->find((int) $this->route('user'));
        $actor = $this->user();
        if ($target === null || $actor === null || ! app(ProfileAccess::class)->canEdit($actor, $target)) {
            throw new NotFoundHttpException;
        }

        return true;
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
