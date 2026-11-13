<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Auth\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberSecondFactorController extends Controller
{
    // Members are resolved here, not by route model binding: binding runs before access:admin and would 404 non-admins.
    public function destroy(Request $request, int $user, SessionRevoker $revoker, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $member = User::query()->findOrFail($user);
        $admin = $request->user();

        abort_if($member->is($admin), 403);

        if (! $member->hasSecondFactor()) {
            return response()->json(['error' => 'no_factor'], 422);
        }

        // One transaction: factors, sessions and the audit row change together or not at all.
        DB::transaction(function () use ($revoker, $audit, $admin, $member): void {
            $member->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            // A query, not the package action: no per-passkey events.
            DB::table('passkeys')->where('user_id', $member->getKey())->delete();
            $revoker->revokeAll($member);
            $audit->record(AuditEvent::SecondFactorReset, $admin, [], $member);
        });

        if ($request->expectsJson()) {
            return response()->json(['reset' => true]);
        }

        return back(303)->with('toast', ['key' => 'members.security.reset_toast', 'params' => ['name' => $member->name]]);
    }
}
