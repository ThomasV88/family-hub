<?php

namespace App\Wishlist\Fetch;

use DOMDocument;
use DOMXPath;

/** Finds product image candidates in page HTML. Pure parsing; no network access. */
final class MetaImageExtractor
{
    private const MAX_HTML = 524288;

    private const MAX_CANDIDATES = 5;

    private const JSON_DEPTH = 32;

    private const LOWER = "translate(%s,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";

    /** @return list<string> absolute http(s) URLs in priority order, deduplicated, at most 5 */
    public static function candidates(string $html, string $baseUrl): array
    {
        $html = substr($html, 0, self::MAX_HTML);
        if ($html === '') {
            return [];
        }
        $html = self::toUtf8($html);

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($doc);

        $raw = [];
        foreach ([['property', 'og:image:secure_url'], ['property', 'og:image'], ['name', 'twitter:image'], ['name', 'twitter:image:src'],
            ['property', 'twitter:image'], ['property', 'twitter:image:src']] as [$attr, $name]) {
            foreach (self::metaContents($xp, $attr, $name) as $value) {
                $raw[] = $value;
            }
        }
        $links = $xp->query("//link[contains(concat(' ', normalize-space(".sprintf(self::LOWER, '@rel')."), ' '), ' image_src ')]/@href");
        foreach ($links ?: [] as $node) {
            $raw[] = trim($node->nodeValue ?? '');
        }
        foreach (self::jsonLdImages($xp) as $value) {
            $raw[] = $value;
        }

        $out = [];
        foreach ($raw as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $abs = UrlResolver::resolve($baseUrl, $candidate);
            if ($abs !== null && ! in_array($abs, $out, true)) {
                $out[] = $abs;
                if (count($out) >= self::MAX_CANDIDATES) {
                    break;
                }
            }
        }

        return $out;
    }

    private static function toUtf8(string $html): string
    {
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_\-:.]+)/i', substr($html, 0, 4096), $m) !== 1) {
            return $html;
        }
        $charset = strtoupper($m[1]);
        if (in_array($charset, ['UTF-8', 'UTF8'], true)) {
            return $html;
        }
        try {
            return mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $html);

            return $converted === false ? $html : $converted;
        }
    }

    /** @return list<string> */
    private static function metaContents(DOMXPath $xp, string $attr, string $name): array
    {
        $nodes = $xp->query('//meta['.sprintf(self::LOWER, '@'.$attr)."='".$name."']/@content");
        $values = [];
        foreach ($nodes ?: [] as $node) {
            $values[] = trim($node->nodeValue ?? '');
        }

        return $values;
    }

    /** @return list<string> */
    private static function jsonLdImages(DOMXPath $xp): array
    {
        $images = [];
        $scripts = $xp->query('//script['.sprintf(self::LOWER, '@type')."='application/ld+json']");
        foreach ($scripts ?: [] as $script) {
            $data = json_decode($script->textContent, true, self::JSON_DEPTH);
            if (! is_array($data)) {
                continue;
            }
            $queue = [[$data, 0]];
            for ($i = 0; $i < count($queue) && $i < 5000; $i++) {
                [$node, $depth] = $queue[$i];
                if (! is_array($node)) {
                    continue;
                }
                if (self::isProduct($node) && isset($node['image'])) {
                    array_push($images, ...self::imageValues($node['image']));
                }
                if ($depth < self::JSON_DEPTH) {
                    foreach ($node as $child) {
                        if (is_array($child)) {
                            $queue[] = [$child, $depth + 1];
                        }
                    }
                }
            }
        }

        return $images;
    }

    /** @param  array<mixed>  $node */
    private static function isProduct(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if (is_string($type)) {
            return strcasecmp($type, 'Product') === 0;
        }
        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && strcasecmp($t, 'Product') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function imageValues(mixed $image): array
    {
        $values = [];
        $stack = [[$image, 0]];
        while ($stack !== []) {
            [$item, $depth] = array_shift($stack);
            if (is_string($item)) {
                $values[] = trim($item);
            } elseif (is_array($item) && $depth < 4) {
                if (array_is_list($item)) {
                    foreach ($item as $child) {
                        $stack[] = [$child, $depth + 1];
                    }
                } else {
                    foreach (['url', 'contentUrl'] as $key) {
                        if (isset($item[$key]) && is_string($item[$key])) {
                            $values[] = trim($item[$key]);
                            break;
                        }
                    }
                }
            }
        }

        return $values;
    }
}
