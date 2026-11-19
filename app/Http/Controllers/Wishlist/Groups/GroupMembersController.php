<?php

namespace App\Http\Controllers\Wishlist\Groups;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\GroupMembersRequest;
use App\Models\WishlistGroup;
use App\Wishlist\Giving\GroupReservationPurger;
use App\Wishlist\Groups\GroupMode;
use App\Wishlist\Groups\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupMembersController extends Controller
{
    public function update(GroupMembersRequest $request, int $group, GroupService $service, GroupReservationPurger $purger): RedirectResponse
    {
        $model = WishlistGroup::query()->find($group);
        abort_if($model === null, 403);

        DB::transaction(function () use ($request, $model, $service, $purger) {
            $changes = $service->syncMembers($request->user(), $model, $request->memberIds());
            // Gifts: a new member is recipient-side now. Shared: a removed member lost access.
            $affected = $model->mode === GroupMode::Gifts ? $changes['added'] : $changes['removed'];
            foreach ($affected as $userId) {
                $purger->purgeMember($model->id, $userId);
            }
        });

        return back(303)->with('toast', ['key' => 'wishlist.toast.group_saved']);
    }

    public function destroy(Request $request, int $group, GroupService $service, GroupReservationPurger $purger): RedirectResponse
    {
        $model = WishlistGroup::query()->find($group);
        abort_if($model === null, 403);

        DB::transaction(function () use ($request, $model, $service, $purger) {
            $mode = $service->leave($request->user(), $model);
            if ($mode === GroupMode::Shared) {
                $purger->purgeMember($model->id, $request->user()->id);
            }
        });

        return redirect()->route('wishlist.index', [], 303)->with('toast', ['key' => 'wishlist.toast.group_left']);
    }
}
