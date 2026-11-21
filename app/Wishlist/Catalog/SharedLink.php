<?php

namespace App\Wishlist\Catalog;

use App\Http\Requests\Wishlist\WishRequest;

/**
 * Turns the title, text and url a mobile browser sends through the Web Share Target into a safe prefill for the new-wish form.
 * Android Chrome often puts the link into "text" instead of "url", so both are searched. Returns plain strings only.
 */
final class SharedLink
{
    private const MAX_URL = 2048;

    /** @return array{url: ?string, title: string} */
    public static function from(?string $title, ?string $text, ?string $url): array
    {
        $url = self::clean($url);
        $text = (string) $text;

        $found = null;
        if ($url !== null && self::isSafe($url)) {
            $found = $url;
        } elseif (preg_match('#https?://[^\s<>"\']+#i', $text, $m) === 1) {
            $candidate = rtrim($m[0], '.,);:!?"\'');
            if (self::isSafe($candidate)) {
                $found = $candidate;
            }
        }

        $title = self::clean($title) ?? '';
        if ($title === '') {
            $stripped = preg_replace('#https?://[^\s<>"\']+#i', ' ', $text) ?? '';
            $title = trim(self::clean($stripped) ?? '', " \t-:,;.");
        }

        return ['url' => $found, 'title' => mb_substr($title, 0, (int) config('wishlist.title_max', 120))];
    }

    private static function isSafe(string $url): bool
    {
        if (strlen($url) > self::MAX_URL || ! WishRequest::isWebUrl($url)) {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    /** Control characters out, whitespace collapsed, trimmed. */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
