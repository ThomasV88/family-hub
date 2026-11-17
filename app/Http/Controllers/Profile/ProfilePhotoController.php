<?php

namespace App\Http\Controllers\Profile;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UploadProfilePhotoRequest;
use App\Models\User;
use App\Profile\ProfileAccess;
use App\Profile\ProfilePhotoAccess;
use App\Profile\ProfilePhotoStore;
use App\Profile\ProfilePhotoWriteFailed;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfilePhotoController extends Controller
{
    /**
     * Streams a private photo. Revalidated on every view (no-cache + ETag), so access is re-checked each time.
     * Every refusal is the same 404 as an unknown id or a missing photo.
     */
    public function show(Request $request, int $user, string $variant, ProfilePhotoAccess $access): Response
    {
        $subject = User::query()->find($user);
        abort_if($subject === null || ! $access->canView($request->user(), $subject), 404);
        $path = $variant === 'thumb' ? $subject->photo_thumb_path : ($variant === 'full' ? $subject->photo_path : null);
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

    public function store(UploadProfilePhotoRequest $request, int $user, ImageProcessor $processor, ProfilePhotoStore $store, AuditLogger $audit): RedirectResponse
    {
        $target = User::query()->findOrFail($user);

        $file = $request->file('photo');
        $bytes = $file === null || ! $file->isValid() ? false : file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['photo' => 'profile.photo.err_decode']);
        }

        try {
            $processed = $processor->process(
                $bytes,
                (int) config('profile.photo_max_bytes'),
                (int) config('profile.photo_full_px'),
                (int) config('profile.photo_thumb_px'),
            );
        } catch (ImageRejected $e) {
            $key = match ($e->reason) {
                'too_large' => 'profile.photo.err_size',
                'too_many_pixels' => 'profile.photo.err_pixels',
                'busy' => 'profile.photo.err_write',
                default => 'profile.photo.err_type',
            };
            throw ValidationException::withMessages(['photo' => $key]);
        }
        try {
            $store->put($target, $processed);
        } catch (ProfilePhotoWriteFailed) {
            throw ValidationException::withMessages(['photo' => 'profile.photo.err_write']);
        }

        $this->auditIfOther($audit, $request->user(), $target);

        return back(303)->with('toast', ['key' => 'profile.toast.photo_saved']);
    }

    public function destroy(Request $request, int $user, ProfilePhotoStore $store, ProfileAccess $access, AuditLogger $audit): RedirectResponse
    {
        // Unknown id and "not yours to edit" are the same 404, like the view routes.
        $target = User::query()->find($user);
        abort_if($target === null || ! $access->canEdit($request->user(), $target), 404);

        $store->remove($target);
        $this->auditIfOther($audit, $request->user(), $target);

        return back(303)->with('toast', ['key' => 'profile.toast.photo_removed']);
    }

    private function auditIfOther(AuditLogger $audit, User $actor, User $target): void
    {
        if ($actor->id !== $target->id) {
            $audit->record(AuditEvent::ProfileChangedByOther, $actor, ['field' => 'photo'], $target);
        }
    }
}
