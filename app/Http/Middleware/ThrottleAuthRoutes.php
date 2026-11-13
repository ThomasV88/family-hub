<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits for the Fortify password routes, keyed by route name at runtime (Fortify routes cannot
 * carry middleware from config, and a name lookup survives route:cache).
 */
class ThrottleAuthRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($name === 'password.reset') {
            $response = $next($request);
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        $limits = match ($name) {
            'password.email' => [
                ['forgot-ip:'.$request->ip(), 5, 60],
                ['forgot-email:'.Str::lower((string) $request->input('email')), 5, 3600],
            ],
            'password.update' => [
                ['reset-ip:'.$request->ip(), 10, 60],
            ],
            default => [],
        };

        foreach ($limits as [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                return back()->withErrors(['throttle' => (string) $minutes]);
            }
        }
        foreach ($limits as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }
}
