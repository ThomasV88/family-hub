<?php

namespace App\Recipes\Import;

/**
 * The address a member pastes. Only the shape is checked here (http or https, a host, no credentials); whether the
 * host may be contacted is decided by SafeFetcher when the page is fetched.
 */
final class ImportUrl
{
    public const MAX_LENGTH = 2000;

    public static function normalise(string $input): ?string
    {
        $url = trim($input);
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $out = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $out .= ':'.$parts['port'];
        }
        $out .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $out .= '?'.$parts['query'];
        }

        return strlen($out) > self::MAX_LENGTH ? null : $out;
    }

    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    public static function host(string $normalised): string
    {
        return substr((string) parse_url($normalised, PHP_URL_HOST), 0, 255);
    }
}
