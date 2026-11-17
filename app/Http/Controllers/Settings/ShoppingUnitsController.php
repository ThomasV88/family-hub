<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\UnitRequest;
use App\Shopping\Catalog\UnitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingUnitsController extends Controller
{
    public function __construct(private readonly UnitService $units) {}

    public function store(UnitRequest $request): RedirectResponse
    {
        $d = $request->validated();
        $this->units->create($request->user(), $d['name_en'], $d['name_sk'], $d['name_pl']);

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.unit_saved']);
    }

    public function update(UnitRequest $request, int $unit): RedirectResponse
    {
        $d = $request->validated();
        $this->units->update($request->user(), $unit, $d['name_en'], $d['name_sk'], $d['name_pl']);

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.unit_saved']);
    }

    public function destroy(Request $request, int $unit): RedirectResponse
    {
        $this->units->delete($request->user(), $unit);

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.unit_deleted']);
    }
}
