<?php

namespace App\Http\Controllers\Settings;

use App\Access\Preset;
use App\Auth\InviteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreInvitationRequest;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvitationsController extends Controller
{
    public function store(StoreInvitationRequest $request, InviteService $service): RedirectResponse
    {
        $result = $service->create(
            $request->user(),
            $request->validated('email'),
            Preset::from($request->validated('preset')),
            $request->validated('locale'),
        );

        return $this->handoff($result['invitation'], $result['token'], mailQueued: true, rotated: false);
    }

    public function resend(Request $request, int $invitation, InviteService $service): RedirectResponse
    {
        $model = Invitation::query()->findOrFail($invitation);
        $token = $service->resend($request->user(), $model);

        return $token === null
            ? abort(409)
            : $this->handoff($model, $token, mailQueued: true, rotated: true);
    }

    public function link(Request $request, int $invitation, InviteService $service): RedirectResponse
    {
        $model = Invitation::query()->findOrFail($invitation);
        $token = $service->rotateLink($request->user(), $model);

        return $token === null
            ? abort(409)
            : $this->handoff($model, $token, mailQueued: false, rotated: true);
    }

    public function destroy(Request $request, int $invitation, InviteService $service): RedirectResponse
    {
        $model = Invitation::query()->findOrFail($invitation);
        abort_unless($service->revoke($request->user(), $model), 409);

        return back(303);
    }

    /** One-shot handoff of the link to the browser: a session flash, never a query string or persistent prop. */
    private function handoff(Invitation $invitation, string $token, bool $mailQueued, bool $rotated): RedirectResponse
    {
        return back(303)->with('flash_data', ['invite' => [
            'email' => $invitation->email,
            'link' => InviteService::link($token),
            'mailQueued' => $mailQueued,
            'rotated' => $rotated,
        ]]);
    }
}
