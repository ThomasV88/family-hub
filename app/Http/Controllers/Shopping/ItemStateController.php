<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\ItemStateRequest;
use App\Shopping\ItemState;
use App\Shopping\ListStateService;
use Illuminate\Http\RedirectResponse;

/** Marking an item while shopping: no toast, the row itself shows the new state. */
class ItemStateController extends Controller
{
    public function __invoke(ItemStateRequest $request, int $list, int $item, ListStateService $service): RedirectResponse
    {
        $service->setItemState($request->user(), $list, $item, ItemState::from($request->validated()['state']));

        return back(303);
    }
}
