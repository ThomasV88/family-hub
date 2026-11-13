<?php

namespace App\Http\Controllers\Wishlist\Giving;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\ContributionRequest;
use App\Models\Wish;
use App\Wishlist\Giving\CoBuyService;
use App\Wishlist\Giving\GivingGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    public function update(ContributionRequest $request, int $wish, GivingGuard $guard, CoBuyService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $service->contribute($request->user(), $model, $request->amountMinor());

        return back(303)->with('toast', ['key' => 'wishlist.toast.joined']);
    }

    public function destroy(Request $request, int $wish, GivingGuard $guard, CoBuyService $service): RedirectResponse
    {
        $model = Wish::query()->findOrFail($wish);
        $guard->authorize($request->user(), $model);

        $service->leave($request->user(), $model);

        return back(303)->with('toast', ['key' => 'wishlist.toast.left']);
    }
}
