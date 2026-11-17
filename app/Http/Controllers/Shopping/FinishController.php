<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\FinishListRequest;
use App\Shopping\ListStateService;
use Illuminate\Http\RedirectResponse;

class FinishController extends Controller
{
    public function __invoke(FinishListRequest $request, int $list, ListStateService $service): RedirectResponse
    {
        $result = $service->finish($request->user(), $list, $request->validated()['carry_name'] ?? null);

        if ($result['carry'] !== null) {
            return redirect()->route('shopping.lists.show', $result['carry']->id, 303)
                ->with('toast', ['key' => 'shopping.toast.finished_carry']);
        }

        return back(303)->with('toast', ['key' => 'shopping.toast.finished']);
    }
}
