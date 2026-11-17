<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\CatalogItemRequest;
use App\Shopping\Catalog\CatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingCatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function store(CatalogItemRequest $request): RedirectResponse
    {
        $this->catalog->create($request->user(), $request->validated('name'), $request->validated('default_unit_id'));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.catalog_saved']);
    }

    public function update(CatalogItemRequest $request, int $item): RedirectResponse
    {
        $this->catalog->update($request->user(), $item, $request->validated('name'), $request->validated('default_unit_id'));

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.catalog_saved']);
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $this->catalog->delete($request->user(), $item);

        return back(303)->with('toast', ['key' => 'shopping.settings.toast.catalog_deleted']);
    }
}
