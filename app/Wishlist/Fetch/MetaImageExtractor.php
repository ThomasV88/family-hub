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
        $xp = self::load($html);
        if ($xp === null) {
            return [];
        }

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

        if ($raw === [] || count(array_filter($raw, fn ($v) => $v !== '')) === 0) {
            $raw[] = self::largeImg($xp);
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

    /** Page title: og:title, twitter:title, JSON-LD Product name, then the title element. Plain text, at most title_max. */
    public static function title(string $html): ?string
    {
        $xp = self::load($html);
        if ($xp === null) {
            return null;
        }
        $options = [
            ...self::metaContents($xp, 'property', 'og:title'),
            ...self::metaContents($xp, 'name', 'og:title'),
            ...self::metaContents($xp, 'name', 'twitter:title'),
            ...self::metaContents($xp, 'property', 'twitter:title'),
            ...self::jsonLdText($xp, 'name'),
            ...self::titleElement($xp),
        ];

        return self::firstText($options, self::limit('title_max', 120));
    }

    /** Page description: meta description and og:description first, then twitter and JSON-LD. Plain text, at most notes_max. */
    public static function description(string $html): ?string
    {
        $xp = self::load($html);
        if ($xp === null) {
            return null;
        }
        $options = [
            ...self::metaContents($xp, 'name', 'description'),
            ...self::metaContents($xp, 'property', 'og:description'),
            ...self::metaContents($xp, 'name', 'og:description'),
            ...self::metaContents($xp, 'name', 'twitter:description'),
            ...self::metaContents($xp, 'property', 'twitter:description'),
            ...self::jsonLdText($xp, 'description'),
        ];

        return self::firstText($options, self::limit('notes_max', 2000));
    }

    /** The configured cap, or the default when no application container is booted (plain unit tests). */
    private static function limit(string $key, int $default): int
    {
        return function_exists('app') && app()->bound('config') ? (int) config('wishlist.'.$key, $default) : $default;
    }

    private static function load(string $html): ?DOMXPath
    {
        $html = substr($html, 0, self::MAX_HTML);
        if ($html === '') {
            return null;
        }
        $html = self::toUtf8($html);

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** @param  list<string>  $options */
    private static function firstText(array $options, int $max): ?string
    {
        foreach ($options as $option) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($option, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            if ($text !== '') {
                return mb_substr($text, 0, $max);
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function titleElement(DOMXPath $xp): array
    {
        $values = [];
        foreach ($xp->query('//title') ?: [] as $node) {
            $values[] = $node->textContent;
        }

        return $values;
    }

    /** @return list<string> */
    private static function jsonLdText(DOMXPath $xp, string $key): array
    {
        $values = [];
        foreach ($xp->query('//script['.sprintf(self::LOWER, '@type')."='application/ld+json']") ?: [] as $script) {
            $data = json_decode($script->textContent, true, self::JSON_DEPTH);
            if (! is_array($data)) {
                continue;
            }
            $queue = [[$data, 0]];
            for ($i = 0; $i < count($queue) && $i < 5000; $i++) {
                [$node, $depth] = $queue[$i];
                if (self::isProduct($node) && isset($node[$key]) && is_string($node[$key])) {
                    $values[] = $node[$key];
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

        return $values;
    }

    /** First img with a width or height attribute of at least 200, as a last-resort picture. */
    private static function largeImg(DOMXPath $xp): string
    {
        foreach ($xp->query('//img[@src]') ?: [] as $img) {
            foreach (['width', 'height'] as $attr) {
                if ((int) $img->getAttribute($attr) >= 200) {
                    return trim($img->getAttribute('src'));
                }
            }
        }

        return '';
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
