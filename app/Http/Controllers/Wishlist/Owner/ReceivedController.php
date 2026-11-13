<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use App\Wishlist\Catalog\WishService;
use App\Wishlist\WishAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReceivedController extends Controller
{
    public function store(Request $request, int $wish, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditList($user, $model->owner_id), 403);

        $service->markReceived($user, $model);

        return back(303)->with('toast', ['key' => 'wishlist.toast.received']);
    }

    public function destroy(Request $request, int $wish, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditList($user, $model->owner_id), 403);

        $service->unmarkReceived($user, $model);

        return back(303);
    }
}
