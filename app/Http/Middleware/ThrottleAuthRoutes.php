<?php

namespace App\Http\Middleware;

use App\Support\RequestInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
            // The page discloses whether a token/email pair is still valid, so it is throttled too.
            $key = 'reset-view-ip:'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($key, 20), 429);
            RateLimiter::hit($key, 60);

            $response = $next($request);
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        // Fortify lowercases the email before it validates, so array-valued fields would end in a 500.
        if (in_array($name, ['password.email', 'password.update'], true)) {
            foreach (['email', 'token', 'password', 'password_confirmation'] as $field) {
                abort_if($request->has($field) && ! is_string($request->input($field)), 422);
            }
        }

        $limits = match ($name) {
            'password.email' => [
                ['forgot-ip:'.$request->ip(), 5, 60],
                ['forgot-email:'.RequestInput::email($request), 5, 3600],
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
