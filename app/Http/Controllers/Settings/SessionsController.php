<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Auth\SessionList;
use App\Auth\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserAgentLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionsController extends Controller
{
    // Members are resolved here, not by route model binding: binding runs before access:admin and would 404 non-admins.
    public function index(Request $request, int $user): JsonResponse
    {
        $member = User::query()->findOrFail($user);

        return response()->json(['sessions' => SessionList::for($member, $request->session()->getId())]);
    }

    public function destroy(Request $request, int $user, string $session, SessionRevoker $revoker, AuditLogger $audit): RedirectResponse
    {
        $member = User::query()->findOrFail($user);
        $row = DB::table('sessions')->where('id', $session)->where('user_id', $member->getKey())->first(['user_agent']);
        abort_if($row === null, 404);

        $revoker->revokeOne($member, $session);
        $audit->record(AuditEvent::SessionRevoked, $request->user(), ['device' => UserAgentLabel::device($row->user_agent)], $member);

        return back(303)->with('toast', ['key' => 'sessions.revoked_toast']);
    }

    public function destroyAll(Request $request, int $user, SessionRevoker $revoker, AuditLogger $audit): RedirectResponse
    {
        $member = User::query()->findOrFail($user);

        $revoker->revokeAll($member);
        $audit->record(AuditEvent::SessionRevokedAll, $request->user(), [], $member);

        return back(303)->with('toast', ['key' => 'sessions.revoked_toast']);
    }
}
