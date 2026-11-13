<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic responses (HTML, Inertia JSON, redirects) must never land in any browser or proxy cache.
 * Static files are served by nginx and never reach PHP.
 */
class NoStoreDynamicResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
