<?php

namespace App\Http\Controllers\Wishlist\Giving;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use App\Wishlist\Giving\CoBuyService;
use App\Wishlist\Giving\GivingGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CoBuyController extends Controller
{
    public function store(Request $request, int $wish, GivingGuard $guard, CoBuyService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $service->open($request->user(), $model);

        return back(303)->with('toast', ['key' => 'wishlist.toast.cobuy_open']);
    }

    public function destroy(Request $request, int $wish, GivingGuard $guard, CoBuyService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $service->close($request->user(), $model);

        return back(303)->with('toast', ['key' => 'wishlist.toast.cobuy_closed']);
    }
}
