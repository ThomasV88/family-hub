<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\OccasionRequest;
use App\Models\WishOccasion;
use App\Wishlist\Occasions\OccasionAccess;
use App\Wishlist\Occasions\OccasionsPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class OccasionController extends Controller
{
    public function index(Request $request, OccasionsPage $page): Response
    {
        return $page->render($request->user());
    }

    public function store(OccasionRequest $request): RedirectResponse
    {
        $occasion = new WishOccasion;
        $this->fill($occasion, $request);
        $occasion->forceFill(['kind' => WishOccasion::CUSTOM, 'created_by_id' => $request->user()->id])->save();

        return redirect()->route('wishlist.occasions.index', [], 303)
            ->with('toast', ['key' => 'occasions.toast.saved']);
    }

    public function update(OccasionRequest $request, int $occasion, OccasionAccess $access): RedirectResponse
    {
        $model = $access->editableCustom($request->user(), $occasion);
        $this->fill($model, $request);
        $model->save();

        return redirect()->route('wishlist.occasions.index', [], 303)
            ->with('toast', ['key' => 'occasions.toast.saved']);
    }

    public function destroy(Request $request, int $occasion, OccasionAccess $access): RedirectResponse
    {
        $access->editableCustom($request->user(), $occasion)->delete();

        return redirect()->route('wishlist.occasions.index', [], 303)
            ->with('toast', ['key' => 'occasions.toast.deleted']);
    }

    private function fill(WishOccasion $occasion, OccasionRequest $request): void
    {
        [$year, $month, $day] = array_map('intval', explode('-', $request->validated('date')));
        $owner = $request->validated('owner_id');
        $occasion->fill([
            'title' => $request->validated('title'),
            'month' => $month,
            'day' => $day,
            'year' => $request->boolean('yearly') ? null : $year,
        ]);
        $occasion->forceFill(['owner_id' => $owner === null ? null : (int) $owner]);
    }
}
