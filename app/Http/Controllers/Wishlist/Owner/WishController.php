<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\WishRequest;
use App\Models\Wish;
use App\Wishlist\Catalog\WishService;
use App\Wishlist\WishAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WishController extends Controller
{
    public function store(WishRequest $request, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $owner = (int) ($request->validated('owner_id') ?? $user->id);
        abort_unless($access->canEditList($user, $owner), 403);

        $wish = $service->create($user, $owner, $request->validated());

        return redirect()->route('wishlist.index', ['member' => $owner], 303)
            ->with('flash_data', ['wishId' => $wish->id])
            ->with('toast', ['key' => 'wishlist.toast.saved']);
    }

    public function update(WishRequest $request, int $wish, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditList($user, $model->owner_id), 403);

        $service->update($user, $model, $request->validated());

        return back(303)->with('toast', ['key' => 'wishlist.toast.saved']);
    }

    public function destroy(Request $request, int $wish, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditList($user, $model->owner_id), 403);

        $service->delete($user, $model);

        return redirect()->route('wishlist.index', ['member' => $model->owner_id], 303)
            ->with('toast', ['key' => 'wishlist.toast.deleted']);
    }
}
