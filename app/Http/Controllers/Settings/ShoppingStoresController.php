<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreRequest;
use App\Shopping\Catalog\StoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingStoresController extends Controller
{
    public function __construct(private readonly StoreService $stores) {}

    public function store(StoreRequest $request): RedirectResponse
    {
        $this->stores->create($request->user(), $request->validated('name'));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.store_saved']);
    }

    public function update(StoreRequest $request, int $store): RedirectResponse
    {
        $this->stores->update($request->user(), $store, $request->validated('name'));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.store_saved']);
    }

    public function destroy(Request $request, int $store): RedirectResponse
    {
        $this->stores->delete($request->user(), $store);

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.store_deleted']);
    }
}
