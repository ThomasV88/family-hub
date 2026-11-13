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
        'password.confirm' => ['authenticated'],
        'password.confirm.store' => ['authenticated'],
        'password.confirmation' => ['authenticated'],
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
