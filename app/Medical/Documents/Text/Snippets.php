<?php

namespace App\Medical\Documents\Text;

/**
 * Search result excerpts built from the stored text. Output is plain {text, hit} segments in the original spelling:
 * no markup, no ts_headline, no ellipsis (the view adds those). Words are found by walking the original text and folding
 * each word on its own, so a transliteration that changes the length (ß) cannot shift a boundary. Pure PHP: no database
 * code, no logging.
 */
final class Snippets
{
    private const PAGE = "\f";

    /**
     * @param  list<list<string>>  $terms  SearchTerms::parse() output
     * @return array{snippets: list<list<array{text: string, hit: bool}>>, page: ?int}
     */
    public static function build(string $body, array $terms, int $max = 2, int $radius = 80): array
    {
        $none = ['snippets' => [], 'page' => null];
        if ($body === '' || $terms === [] || $max < 1) {
            return $none;
        }

        // Folded alternatives, once: the same comparison as SearchTerms::matches without refolding per word.
        $groups = [];
        foreach ($terms as $alternatives) {
            $folded = array_values(array_filter(array_map(SearchTerms::fold(...), $alternatives), fn (string $a) => $a !== ''));
            if ($folded !== []) {
                $groups[] = $folded;
            }
        }
        if ($groups === [] || ! preg_match_all('/[\p{L}\p{N}]+/u', $body, $m, PREG_OFFSET_CAPTURE)) {
            return $none;
        }

        /** @var list<array{string, int}> $words */
        $words = $m[0];
        $count = count($words);
        $hits = [];
        $windows = [];
        for ($i = 0; $i < $count; $i++) {
            [$word, $start] = $words[$i];
            if (count($windows) === $max && $start > $windows[$max - 1][1]) {
                break;
            }
            if (! self::isHit($word, $groups)) {
                continue;
            }
            $hits[] = [$start, $start + strlen($word)];
            [$ws, $we] = self::window($body, $words, $i, $radius);
            $last = count($windows) - 1;
            if ($last >= 0 && $ws <= $windows[$last][1]) {
                $windows[$last][1] = max($windows[$last][1], $we);
            } elseif (count($windows) < $max) {
                $windows[] = [$ws, $we];
            }
        }
        if ($hits === []) {
            return $none;
        }

        $snippets = [];
        foreach ($windows as [$ws, $we]) {
            $inside = array_values(array_filter($hits, fn (array $h) => $h[0] >= $ws && $h[1] <= $we));
            $segments = self::segments($body, $ws, $we, $inside);
            if ($segments !== []) {
                $snippets[] = $segments;
            }
        }

        return ['snippets' => $snippets, 'page' => 1 + substr_count($body, self::PAGE, 0, $hits[0][0])];
    }

    /** @param  list<list<string>>  $groups */
    private static function isHit(string $word, array $groups): bool
    {
        $folded = SearchTerms::fold($word);
        foreach ($groups as $alternatives) {
            foreach ($alternatives as $a) {
                if (str_starts_with($folded, $a)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Byte range of about $radius characters either side of the hit word number $i, clamped to the hit's own page and
     * trimmed to whole words.
     *
     * @param  list<array{string, int}>  $words
     * @return array{int, int}
     */
    private static function window(string $body, array $words, int $i, int $radius): array
    {
        [$word, $start] = $words[$i];
        $end = $start + strlen($word);

        $pageStart = strrpos(substr($body, 0, $start), self::PAGE);
        $pageStart = $pageStart === false ? 0 : $pageStart + 1;
        $pageEnd = strpos($body, self::PAGE, $end);
        $pageEnd = $pageEnd === false ? strlen($body) : $pageEnd;

        // Characters, not bytes: take generous bytes, then count characters from the hit outwards.
        $from = max($pageStart, $start - $radius * 4);
        $before = substr($body, $from, $start - $from);
        $ws = $start - strlen(mb_substr($before, -$radius));
        $ws = max($ws, $pageStart);
        $to = min($pageEnd, $end + $radius * 4);
        $after = substr($body, $end, $to - $end);
        $we = $end + strlen(mb_substr($after, 0, $radius));
        $we = min($we, $pageEnd);

        // A cut inside a word drops that partial word (the words list gives the exact boundaries).
        $j = $i;
        while ($j > 0 && $words[$j - 1][1] >= $ws) {
            $j--;
        }
        if ($j > 0 && $words[$j - 1][1] + strlen($words[$j - 1][0]) > $ws) {
            $ws = $words[$j - 1][1] + strlen($words[$j - 1][0]);
        }
        $k = $i;
        $n = count($words);
        while ($k + 1 < $n && $words[$k + 1][1] + strlen($words[$k + 1][0]) <= $we) {
            $k++;
        }
        if ($k + 1 < $n && $words[$k + 1][1] < $we) {
            $we = $words[$k][1] + strlen($words[$k][0]);
        }

        return [min($ws, $start), max($we, $end)];
    }

    /**
     * @param  list<array{int, int}>  $hits  byte ranges inside the window, ascending
     * @return list<array{text: string, hit: bool}>
     */
    private static function segments(string $body, int $ws, int $we, array $hits): array
    {
        $parts = [];
        $at = $ws;
        foreach ($hits as [$hs, $he]) {
            if ($hs > $at) {
                $parts[] = [substr($body, $at, $hs - $at), false];
            }
            $parts[] = [substr($body, $hs, $he - $hs), true];
            $at = $he;
        }
        if ($at < $we) {
            $parts[] = [substr($body, $at, $we - $at), false];
        }

        $out = [];
        foreach ($parts as [$text, $hit]) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            if ($text !== '') {
                $out[] = ['text' => $text, 'hit' => $hit];
            }
        }
        if ($out !== []) {
            $out[0]['text'] = ltrim($out[0]['text']);
            $last = count($out) - 1;
            $out[$last]['text'] = rtrim($out[$last]['text']);
        }

        return array_values(array_filter($out, fn (array $s) => $s['text'] !== ''));
    }
}
