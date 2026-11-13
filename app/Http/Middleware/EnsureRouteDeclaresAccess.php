<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed guard in the web group: a matched route without an `access:` rule answers 403.
 * Fortify routes cannot carry per-route middleware from config, so they are mapped here.
 */
class EnsureRouteDeclaresAccess
{
    /** Route name => RequireAccess parameters. */
    public const FORTIFY_RULES = [
        'login' => ['guest'],
        'login.store' => ['guest'],
        'logout' => ['authenticated'],
        'password.request' => ['guest'],
        'password.email' => ['guest'],
        'password.reset' => ['guest'],
        'password.update' => ['guest'],
        // Fortify's password-confirm endpoints are an unthrottled password oracle: denied (unknown rule = deny).
        'password.confirm' => ['disabled'],
        'password.confirm.store' => ['disabled'],
        'password.confirmation' => ['disabled'],
        'two-factor.login' => ['guest'],
        'two-factor.login.store' => ['guest'],
        // Enrolling a factor needs the 10-minute security confirmation (423 confirm_required when stale).
        'two-factor.enable' => ['recent'],
        'two-factor.confirm' => ['recent'],
        // Reachable only while a pending secret exists (GuardTwoFactorRoutes); disable checks recency itself
        // so "Cancel setup" of an unconfirmed secret never needs a confirmation.
        'two-factor.disable' => ['authenticated'],
        'two-factor.qr-code' => ['authenticated'],
        'two-factor.secret-key' => ['authenticated'],
        'two-factor.regenerate-recovery-codes' => ['recent'],
        // Returns the plaintext recovery codes at any time: never.
        'two-factor.recovery-codes' => ['disabled'],
    ];

    public function __construct(private readonly RequireAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route === null) {
            return $next($request); // 404 handled by the router
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'access:')) {
                return $next($request);
            }
        }

        $mapped = self::FORTIFY_RULES[$route->getName()] ?? null;
        if ($mapped !== null) {
            return $this->access->handle($request, $next, ...$mapped);
        }

        Log::warning('Route without access rule refused', ['route' => $route->getName(), 'uri' => $route->uri()]);
        abort(403);
    }
}
