<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The one switch for passkeys (SECURITY_PASSKEYS_ENABLED): off means every passkey route is a plain 404. */
class EnsurePasskeysEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('security.passkeys_enabled'), 404);

        return $next($request);
    }
}
