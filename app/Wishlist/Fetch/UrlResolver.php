<?php

namespace App\Wishlist\Fetch;

/** RFC 3986 section 5 reference resolution, restricted to http(s) results. */
final class UrlResolver
{
    private const RE = '~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s';

    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim($ref);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $ref) === 1 || ! preg_match(self::RE, $ref, $r, PREG_UNMATCHED_AS_NULL) || ! preg_match(self::RE, $base, $b, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $rScheme = $r[1] !== null && $r[1] !== '' ? strtolower($r[1]) : null;
        $rAuth = $r[2];
        $rPath = $r[3] ?? '';
        $rQuery = $r[4];

        $bScheme = strtolower($b[1] ?? '');
        $bAuth = $b[2] ?? '';
        $bPath = $b[3] ?? '';
        $bQuery = $b[4];

        if ($rScheme !== null) {
            $scheme = $rScheme;
            $auth = $rAuth;
            $path = self::removeDots($rPath);
            $query = $rQuery;
        } else {
            $scheme = $bScheme;
            if ($rAuth !== null) {
                $auth = $rAuth;
                $path = self::removeDots($rPath);
                $query = $rQuery;
            } else {
                $auth = $bAuth;
                if ($rPath === '') {
                    $path = $bPath;
                    $query = $rQuery ?? $bQuery;
                } else {
                    if (str_starts_with($rPath, '/')) {
                        $path = self::removeDots($rPath);
                    } else {
                        $merged = ($bAuth !== '' && $bPath === '') ? '/'.$rPath : (substr($bPath, 0, (int) strrpos($bPath, '/') + 1).$rPath);
                        $path = self::removeDots($merged);
                    }
                    $query = $rQuery;
                }
            }
        }

        if (! in_array($scheme, ['http', 'https'], true) || $auth === null || $auth === '') {
            return null;
        }

        return $scheme.'://'.$auth.($path === '' ? '/' : $path).($query !== null ? '?'.$query : '');
    }

    private static function removeDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($seg === '.') {
                if ($i === $last) {
                    $out[] = '';
                }
            } elseif ($seg === '..') {
                if (count($out) > 1 || (count($out) === 1 && $out[0] !== '')) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
            } else {
                $out[] = $seg;
            }
        }
        $result = implode('/', $out);
        if (str_starts_with($path, '/') && ! str_starts_with($result, '/')) {
            $result = '/'.$result;
        }

        return $result;
    }
}
