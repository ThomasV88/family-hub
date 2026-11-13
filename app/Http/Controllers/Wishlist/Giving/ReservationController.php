<?php

namespace App\Http\Controllers\Wishlist\Giving;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use App\Wishlist\Giving\GivingGuard;
use App\Wishlist\Giving\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function store(Request $request, int $wish, GivingGuard $guard, ReservationService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $existing = $service->reserve($request->user(), $model);
        if ($existing !== null) {
            return back(303)->with('flash_data', ['reservation_conflict' => ['wish_id' => $model->id, 'name' => $existing->name]]);
        }

        return back(303)->with('toast', ['key' => 'wishlist.toast.reserved']);
    }

    public function destroy(Request $request, int $wish, GivingGuard $guard, ReservationService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $service->cancel($request->user(), $model);

        return back(303)->with('toast', ['key' => 'wishlist.toast.unreserved']);
    }
}
