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
        $groupId = $request->validated('group_id');
        if ($groupId !== null) {
            $groupId = (int) $groupId;
            abort_unless($access->canEditGroup($user, $groupId), 403);

            $wish = $service->createForGroup($user, $groupId, $request->validated());

            return redirect()->route('wishlist.index', ['group' => $groupId], 303)
                ->with('flash_data', ['wishId' => $wish->id])
                ->with('toast', ['key' => 'wishlist.toast.saved']);
        }
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
        abort_unless($access->canEditWish($user, $model), 403);

        $service->update($user, $model, $request->validated());

        return back(303)->with('toast', ['key' => 'wishlist.toast.saved']);
    }

    public function destroy(Request $request, int $wish, WishService $service, WishAccess $access): RedirectResponse
    {
        $user = $request->user();
        $model = Wish::query()->findOrFail($wish);
        abort_unless($access->canEditWish($user, $model), 403);

        $service->delete($user, $model);

        $target = $model->group_id !== null ? ['group' => $model->group_id] : ['member' => $model->owner_id];

        return redirect()->route('wishlist.index', $target, 303)
            ->with('toast', ['key' => 'wishlist.toast.deleted']);
    }
}
