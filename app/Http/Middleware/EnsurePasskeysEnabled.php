<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one switch for passkey MANAGEMENT (SECURITY_PASSKEYS_ENABLED): off means enrolling, renaming and removing
 * passkeys is a plain 404. Signing in with and confirming with passkeys the member already has is deliberately NOT
 * behind it (06-REVIEW WR-03), so flipping the kill switch in an incident does not lock those members out.
 */
class EnsurePasskeysEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('security.passkeys_enabled'), 404);

        return $next($request);
    }
}
