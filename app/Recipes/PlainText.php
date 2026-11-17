<?php

namespace App\Recipes;

/**
 * Turns text that may carry markup into plain text for recipe fields.
 *
 * Never use strip_tags() on recipe text: it treats a `<` followed by anything but a space as the start of a tag and
 * removes everything up to the next `>` (or to the end of the string), so "Bake <180 °C, then <160 °C" loses text
 * silently. Here only a real tag is removed (a `<` or `</` directly followed by a letter, closed by `>`), plus
 * comments, CDATA, processing instructions and the content of script/style/template elements. A literal `<` before a
 * digit, a space or at the end of the text stays.
 */
final class PlainText
{
    private const TAG = '~<!--.*?(?:-->|\z)|<!\[CDATA\[.*?(?:\]\]>|\z)|<\?.*?(?:\?>|>|\z)|<!(?:doctype)[^>]*>|</?[a-z][a-z0-9:-]*(?:\s[^<>]*)?/?>~is';

    private const RAW_ELEMENT = '~<(script|style|template|noscript)\b[^>]*>.*?(?:</\1\s*>|\z)~is';

    public static function strip(string $value): string
    {
        $text = self::removeMarkup($value);
        // Entities are decoded once; the result is cleaned again so that "&lt;script&gt;" cannot come back as a tag.
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $text) {
            $decoded = self::removeMarkup($decoded);
        }

        return $decoded;
    }

    private static function removeMarkup(string $value): string
    {
        $text = preg_replace(self::RAW_ELEMENT, ' ', $value) ?? $value;

        return preg_replace(self::TAG, '', $text) ?? $text;
    }
}
