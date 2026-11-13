<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWishlistManagersRequest;
use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class WishlistManagersController extends Controller
{
    public function update(UpdateWishlistManagersRequest $request, int $user): RedirectResponse
    {
        // Resolved here, not by route model binding: binding runs before access:admin and would 404 non-admins.
        $owner = User::query()->findOrFail($user);

        $this->sync($owner, $request->managerIds(), $request->user());

        return back()->with('toast', ['key' => 'settings.members.wishlist_managers.saved']);
    }

    /**
     * Replace the owner's manager set, keeping rows that stay so their ids and created_at remain stable.
     *
     * @param  list<int>  $managerIds
     * @return list<int> manager ids that were newly added (for the reservation purge job of plan 04-08)
     */
    private function sync(User $owner, array $managerIds, User $admin): array
    {
        return DB::transaction(function () use ($owner, $managerIds, $admin) {
            $existing = WishlistManager::query()->where('owner_id', $owner->id)->pluck('manager_id')->map(fn ($id) => (int) $id)->all();

            WishlistManager::query()->where('owner_id', $owner->id)->whereNotIn('manager_id', $managerIds)->delete();

            $added = array_values(array_diff($managerIds, $existing));
            foreach ($added as $managerId) {
                $row = new WishlistManager;
                $row->forceFill(['owner_id' => $owner->id, 'manager_id' => $managerId, 'created_by_id' => $admin->id])->save();
            }

            return $added;
        });
    }
}
