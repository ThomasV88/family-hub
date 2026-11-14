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

        // The private image route keeps its own "private, no-cache" + ETag so unchanged images revalidate with a 304.
        // Same for health document image previews (200 and 304 only; every other health response stays no-store).
        if (in_array($request->route()?->getName(), ['wishlist.images.show', 'health.documents.preview'], true) && in_array($response->getStatusCode(), [200, 304], true)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
