<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Shopping\ListStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LockController extends Controller
{
    public function __invoke(Request $request, int $list, ListStateService $service): RedirectResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']], ['locked.*' => 'shopping.error.lock_invalid'])['locked'];
        $service->setLocked($request->user(), $list, (bool) $locked);

        return back(303)->with('toast', ['key' => $locked ? 'shopping.toast.locked' : 'shopping.toast.unlocked']);
    }
}
