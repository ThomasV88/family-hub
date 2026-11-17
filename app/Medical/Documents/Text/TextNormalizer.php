<?php

namespace App\Medical\Documents\Text;

/**
 * Turns model output into the plain text that is stored: valid UTF-8, NFC, LF line ends, no control or direction
 * characters, no trailing blanks, bounded length. Pure PHP: no database code, no logging.
 */
final class TextNormalizer
{
    /** Form feed: separates the pages inside the stored body. */
    public const PAGE_SEPARATOR = "\x0c";

    /** Zero-width characters (ZWSP, ZWNJ, ZWJ, word joiner, BOM): invisible, but they split a word for the search index. */
    private const ZERO_WIDTH = '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

    /** Strips the zero-width characters and turns the Unicode line and paragraph separators into LF. */
    public static function stripInvisible(string $text): string
    {
        $text = preg_replace(self::ZERO_WIDTH, '', $text) ?? $text;

        return str_replace(["\u{2028}", "\u{2029}"], "\n", $text);
    }

    public static function page(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = self::stripInvisible($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Form feed (our separator), C0 controls except LF and TAB, DEL, C1 controls, bidi and direction marks.
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
        $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
        $text = $normalised === false ? $text : $normalised;
        $text = preg_replace('/[ \t\p{Zs}]+$/mu', '', $text) ?? '';

        return mb_substr($text, 0, max(0, (int) config('ai.ocr.max_page_chars', 12000)));
    }

    /**
     * Joins the normalised pages with the page separator and keeps the total within the limit: trailing pages that do
     * not fit are dropped whole; only when not even the first page fits is it cut.
     *
     * @param  list<string>  $pages
     */
    public static function body(array $pages): string
    {
        $max = max(0, (int) config('ai.ocr.max_total_chars', 200000));
        $kept = [];
        $length = 0;
        foreach ($pages as $page) {
            $page = self::page($page);
            $add = mb_strlen($page) + ($kept === [] ? 0 : 1);
            if ($length + $add > $max) {
                if ($kept === []) {
                    $kept[] = mb_substr($page, 0, $max);
                }
                break;
            }
            $kept[] = $page;
            $length += $add;
        }

        return implode(self::PAGE_SEPARATOR, $kept);
    }
}
