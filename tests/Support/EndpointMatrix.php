<?php

namespace Tests\Support;

/**
 * Hand-written role-by-endpoint expectations. Each file in EndpointMatrix/*.php returns
 * route name => spec: method, uri (with {placeholders}), params (closure over the roles map,
 * optional), data (optional) and expect (role => status). Expectations are literal on purpose:
 * they must never be derived from the middleware declarations.
 */
class EndpointMatrix
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        // Built once per process: the files only return literals and closures, and rebuilding them on every call
        // (hundreds of calls in the gate proofs) used to bloat memory.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $all = [];
        $files = glob(__DIR__.'/EndpointMatrix/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $all = array_merge($all, require $file);
        }

        return $cache = $all;
    }

    /**
     * URIs (not names) that are not application endpoints. Every entry needs a reason.
     *
     * @return list<string>
     */
    public static function ignored(): array
    {
        return [
            'up', // Laravel health check, unauthenticated by design, outside the web group
        ];
    }

    /**
     * URI prefixes of third-party dev-only routes (unnamed, registered only while Inertia devtools
     * is enabled; they sit in the web group so the fail-closed guard refuses them anyway).
     *
     * @return list<string>
     */
    public static function ignoredPrefixes(): array
    {
        return ['_inertia/devtools'];
    }

    public static function isIgnored(string $uri): bool
    {
        if (in_array($uri, self::ignored(), true)) {
            return true;
        }
        foreach (self::ignoredPrefixes() as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
