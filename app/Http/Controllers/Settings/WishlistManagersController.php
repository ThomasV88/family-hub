<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWishlistManagersRequest;
use App\Models\User;
use App\Wishlist\Giving\PurgeManagerReservations;
use App\Wishlist\WishlistManagerSync;
use Illuminate\Http\RedirectResponse;

class WishlistManagersController extends Controller
{
    public function update(UpdateWishlistManagersRequest $request, int $user, WishlistManagerSync $sync): RedirectResponse
    {
        // Resolved here, not by route model binding: binding runs before access:admin and would 404 non-admins.
        $owner = User::query()->findOrFail($user);

        $added = $sync->sync($owner, $request->managerIds(), $request->user());
        // Newly designated managers become recipient-side: whatever they reserved on this list must vanish.
        foreach ($added as $managerId) {
            PurgeManagerReservations::dispatch($owner->id, $managerId)->afterCommit();
        }

        return back()->with('toast', ['key' => 'settings.members.wishlist_managers.saved']);
    }
}
