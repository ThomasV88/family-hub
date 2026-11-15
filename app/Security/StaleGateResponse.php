<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The 423 a stale confirmation answers to a caller that can open "Confirm it's you" in place (JSON calls and Inertia
 * writes). The body carries which methods the member can use (booleans only), so the dialog needs no extra request.
 * `no-store` is added by the global response middleware like for every other dynamic response.
 */
class StaleGateResponse
{
    /** @param 'confirm_required'|'reauth_required' $error */
    public static function make(string $error, ?User $user): JsonResponse
    {
        $body = ['error' => $error];
        if ($user !== null) {
            $body['methods'] = ReauthMethods::for($user);
        }

        return response()->json($body, 423);
    }

    /** An Inertia write (not a navigation): replaying it after the dialog is the client's job, a redirect would lose the form. */
    public static function inertiaWrite(Request $request): bool
    {
        return (bool) $request->header('X-Inertia') && ! in_array($request->method(), ['GET', 'HEAD'], true);
    }
}
