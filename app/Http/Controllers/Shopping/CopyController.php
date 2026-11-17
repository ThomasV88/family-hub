<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\CopyListRequest;
use App\Shopping\ListStateService;
use Illuminate\Http\RedirectResponse;

class CopyController extends Controller
{
    public function __invoke(CopyListRequest $request, int $list, ListStateService $service): RedirectResponse
    {
        $data = $request->validated();
        $copy = $service->copy($request->user(), $list, $data['name'], (bool) $data['keep']);

        return redirect()->route('shopping.lists.show', $copy->id, 303)->with('toast', ['key' => 'shopping.toast.copied']);
    }
}
