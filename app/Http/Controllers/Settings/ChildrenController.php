<?php

namespace App\Http\Controllers\Settings;

use App\Access\Preset;
use App\Actions\Profile\CreateChild;
use App\Auth\InviteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConvertChildRequest;
use App\Http\Requests\Settings\StoreChildRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ChildrenController extends Controller
{
    public function store(StoreChildRequest $request, CreateChild $create): RedirectResponse
    {
        $child = $create->handle($request->user(), (string) $request->validated('name'), $request->parentIds());

        return redirect('/settings/members', 303)
            ->with('toast', ['key' => 'settings.members.child.toast_added'])
            ->with('flash_data', ['created_child_id' => $child->id]);
    }

    /** Invites a child to become a normal member on the same account; nothing changes until the invitation is accepted. */
    public function convert(ConvertChildRequest $request, int $user, InviteService $invites): RedirectResponse
    {
        $child = User::query()->find($user);
        abort_unless($child !== null && $child->email === null && $child->sign_in_disabled, 404);

        $result = $invites->create(
            $request->user(),
            (string) $request->validated('email'),
            Preset::Child,
            (string) $request->validated('locale'),
            $child->id,
        );

        return back(303)
            ->with('toast', ['key' => 'settings.members.convert.toast_sent', 'params' => ['email' => $result['invitation']->email]])
            ->with('flash_data', ['invite' => [
                'email' => $result['invitation']->email,
                'link' => InviteService::link($result['token']),
                'mailQueued' => true,
                'rotated' => false,
            ]]);
    }
}
