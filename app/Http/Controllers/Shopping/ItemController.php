<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreItemsRequest;
use App\Http\Requests\Shopping\UpdateItemRequest;
use App\Shopping\ItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function store(StoreItemsRequest $request, int $list, ItemService $service): RedirectResponse
    {
        $result = $service->add($request->user(), $list, $request->validated()['items']);

        return back(303)->with('toast', [
            'key' => 'shopping.toast.items_added',
            'params' => ['count' => $result['added'], 'merged' => implode(', ', $result['merged'])],
            'merged' => $result['merged'],
        ]);
    }

    public function update(UpdateItemRequest $request, int $list, int $item, ItemService $service): RedirectResponse
    {
        $service->update($request->user(), $list, $item, $request->validated());

        return back(303)->with('toast', ['key' => 'shopping.toast.item_saved']);
    }

    public function destroy(Request $request, int $list, int $item, ItemService $service): RedirectResponse
    {
        $service->remove($request->user(), $list, $item);

        return back(303)->with('toast', ['key' => 'shopping.toast.item_removed']);
    }
}
