<?php

namespace App\Recipes\Import;

/**
 * The address a member pastes. Only the cheap, DNS-free part of SafeFetcher's rules is checked here (http or https,
 * no credentials, port 80 or 443, a real domain name and not an IP literal, localhost or a single label), so an
 * address that can never be fetched does not spend a daily slot or a queue slot. Whether the host may be contacted
 * is still decided by SafeFetcher when the page is fetched.
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
        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return null;
        }
        $host = self::asciiHost($host);
        if ($host === null) {
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

    /** The ASCII form of a plausible public domain name, or null (IP literal, single label, bad characters). */
    private static function asciiHost(string $host): ?string
    {
        if (str_contains($host, '[') || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (preg_match('/^[\x00-\x7f]*$/', $host) !== 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }

        return strlen($host) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host) === 1 ? $host : null;
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
