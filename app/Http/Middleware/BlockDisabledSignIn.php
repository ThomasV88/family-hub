<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth: whatever created the session (a bug, an old cookie, a remember token, an account disabled
 * after sign-in), an account that cannot sign in is logged out on its very next request. Guests and normal
 * members pass untouched; the already resolved user is reused, so there is no extra query.
 */
class BlockDisabledSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->canSignIn()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect('/login');
        }

        return $next($request);
    }
}
