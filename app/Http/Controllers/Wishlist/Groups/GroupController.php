<?php

namespace App\Http\Controllers\Wishlist\Groups;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\GroupRequest;
use App\Models\WishlistGroup;
use App\Wishlist\Giving\GroupReservationPurger;
use App\Wishlist\Groups\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function store(GroupRequest $request, GroupService $service): RedirectResponse
    {
        $group = $service->create($request->user(), (string) $request->groupName(), $request->mode(), $request->memberIds());

        return redirect()->route('wishlist.index', ['group' => $group->id], 303)
            ->with('toast', ['key' => 'wishlist.toast.group_created']);
    }

    public function update(GroupRequest $request, int $group, GroupService $service, GroupReservationPurger $purger): RedirectResponse
    {
        $model = WishlistGroup::query()->find($group);
        abort_if($model === null, 403);

        DB::transaction(function () use ($request, $model, $service, $purger) {
            // A mode change cancels every reservation on the list in the same transaction (the group row stays locked).
            if ($service->update($request->user(), $model, $request->groupName(), $request->mode(), $request->confirmed())) {
                $purger->purgeGroup($model->id);
            }
        });

        return back(303)->with('toast', ['key' => 'wishlist.toast.group_saved']);
    }

    public function destroy(Request $request, int $group, GroupService $service): RedirectResponse
    {
        $model = WishlistGroup::query()->find($group);
        abort_if($model === null, 403);

        DB::transaction(fn () => $service->delete($request->user(), $model));

        return redirect()->route('wishlist.index', [], 303)->with('toast', ['key' => 'wishlist.toast.group_deleted']);
    }
}
