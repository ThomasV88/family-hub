<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Profile\CreateChild;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreChildRequest;
use Illuminate\Http\RedirectResponse;

class ChildrenController extends Controller
{
    public function store(StoreChildRequest $request, CreateChild $create): RedirectResponse
    {
        $child = $create->handle($request->user(), (string) $request->validated('name'), $request->parentIds());

        return redirect('/settings/members', 303)
            ->with('toast', ['key' => 'settings.members.child.toast_added'])
            ->with('created_child_id', $child->id);
    }
}
