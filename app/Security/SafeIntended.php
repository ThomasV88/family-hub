<?php

namespace App\Security;

use Illuminate\Http\Request;

/** Open-redirect-safe return address: always a same-origin relative path, validated on write and on read. */
class SafeIntended
{
    public const KEY = 'url.intended';

    private const BLOCKED = ['/confirm-access', '/login', '/logout', '/two-factor-challenge', '/auth/google'];

    public static function path(?string $url, string $host): string
    {
        if ($url === null || $url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '/';
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url)) {
            $parts = parse_url($url);
            if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || strtolower($parts['host'] ?? '') !== strtolower($host)) {
                return '/';
            }
            $url = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '/';
        }

        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = strtolower($path);
        foreach (self::BLOCKED as $blocked) {
            if ($path === $blocked || str_starts_with($path, $blocked.'/')) {
                return '/';
            }
        }

        return $url;
    }

    public static function remember(Request $request, string $url): void
    {
        $request->session()->put(self::KEY, self::path($url, $request->getHost()));
    }

    public static function pull(Request $request): string
    {
        return self::path($request->session()->pull(self::KEY), $request->getHost());
    }

    public static function peek(Request $request): ?string
    {
        $value = $request->session()->get(self::KEY);

        return is_string($value) ? self::path($value, $request->getHost()) : null;
    }
}
