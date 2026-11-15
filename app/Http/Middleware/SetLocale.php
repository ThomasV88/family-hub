<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['sk', 'pl', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $cookie = $request->cookie('locale');
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        // getPreferredLanguage returns the first supported entry when nothing matches,
        // so only trust it when the browser actually listed one of our languages.
        $stored = $request->user()?->locale;
        if (is_string($stored) && in_array($stored, self::SUPPORTED, true)) {
            return $stored;
        }

        $listed = array_map(fn (string $l) => strtolower(substr($l, 0, 2)), $request->getLanguages());
        if (array_intersect($listed, self::SUPPORTED) === []) {
            return 'en';
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED);

        return in_array($preferred, self::SUPPORTED, true) ? $preferred : 'en';
    }
}
