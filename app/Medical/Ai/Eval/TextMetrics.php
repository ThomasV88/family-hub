<?php

namespace App\Medical\Ai\Eval;

use App\Medical\Documents\Text\SearchTerms;

/** Text accuracy numbers for the OCR evaluation. Pure functions on strings; nothing is logged or kept. */
final class TextMetrics
{
    public static function normalise(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        $s = str_replace(["\r", "\u{000C}"], ['', ' '], $n === false ? $s : $n);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Edit distance over code points divided by the reference length. An empty reference gives 0 or 1. */
    public static function cer(string $ref, string $hyp): float
    {
        $a = self::chars(self::normalise($ref));
        $b = self::chars(self::normalise($hyp));
        if ($a === []) {
            return $b === [] ? 0.0 : 1.0;
        }

        return self::distance($a, $b) / count($a);
    }

    /** Edit distance over whitespace tokens divided by the reference token count. */
    public static function wer(string $ref, string $hyp): float
    {
        $a = self::words(self::normalise($ref));
        $b = self::words(self::normalise($hyp));
        if ($a === []) {
            return $b === [] ? 0.0 : 1.0;
        }

        return self::distance($a, $b) / count($a);
    }

    /**
     * Share of reference tokens with a non-ASCII letter found exactly in the hypothesis, and the share found only without
     * their diacritics (the model dropped them). Without such tokens: accuracy 1.0, drop 0.0 and `empty` true.
     *
     * @return array{accuracy: float, drop_rate: float, empty: bool}
     */
    public static function diacritics(string $ref, string $hyp): array
    {
        $tokens = array_values(array_filter(
            self::tokens($ref),
            fn (string $t) => preg_match('/[^\x00-\x7F]/u', $t) === 1 && preg_match('/\p{L}/u', $t) === 1,
        ));
        if ($tokens === []) {
            return ['accuracy' => 1.0, 'drop_rate' => 0.0, 'empty' => true];
        }
        $exact = array_flip(self::tokens($hyp));
        $folded = [];
        foreach (array_keys($exact) as $t) {
            $folded[SearchTerms::fold((string) $t)] = true;
        }
        $ok = $drop = 0;
        foreach ($tokens as $t) {
            if (isset($exact[$t])) {
                $ok++;
            } elseif (isset($folded[SearchTerms::fold($t)])) {
                $drop++;
            }
        }

        return ['accuracy' => $ok / count($tokens) + 0.0, 'drop_rate' => $drop / count($tokens) + 0.0, 'empty' => false];
    }

    /** Exact-match share of the reference tokens that contain a digit (doses, dates, ids). 1.0 when there are none. */
    public static function numeric(string $ref, string $hyp): float
    {
        $tokens = array_values(array_filter(self::tokens($ref), fn (string $t) => preg_match('/\p{N}/u', $t) === 1));
        if ($tokens === []) {
            return 1.0;
        }
        $have = array_flip(self::tokens($hyp));
        $ok = 0;
        foreach ($tokens as $t) {
            if (isset($have[$t])) {
                $ok++;
            }
        }

        return $ok / count($tokens) + 0.0;
    }

    /**
     * Share of the reference content words (4 or more letters, one per folded form) that the production query rule finds
     * among the hypothesis tokens. 1.0 when the reference has none.
     */
    public static function searchRecall(string $ref, string $hyp): float
    {
        preg_match_all('/\p{L}{4,}/u', self::normalise($ref), $m);
        $words = [];
        foreach ($m[0] as $w) {
            $words[SearchTerms::fold($w)] ??= $w;
        }
        if ($words === []) {
            return 1.0;
        }
        preg_match_all('/[\p{L}\p{N}]+/u', self::normalise($hyp), $h);
        $hypFolded = array_keys(array_flip(array_map(fn (string $t) => SearchTerms::fold($t), $h[0])));

        $found = 0;
        foreach ($words as $w) {
            $alts = SearchTerms::parse($w)[0] ?? null;
            if ($alts === null) {
                continue;
            }
            $folded = array_map(fn (string $a) => SearchTerms::fold($a), $alts);
            foreach ($hypFolded as $t) {
                foreach ($folded as $a) {
                    if ($a !== '' && str_starts_with($t, $a)) {
                        $found++;

                        continue 3;
                    }
                }
            }
        }

        return $found / count($words) + 0.0;
    }

    /** True when the reference has no content word, digit token or accented token (the metric is then a flagged 1.0). */
    public static function emptyFlags(string $ref): array
    {
        return [
            'numeric' => count(array_filter(self::tokens($ref), fn (string $t) => preg_match('/\p{N}/u', $t) === 1)) === 0,
            'recall' => preg_match('/\p{L}{4,}/u', self::normalise($ref)) !== 1,
        ];
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        $out = [];
        foreach (self::words(self::normalise($s)) as $w) {
            $t = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $w);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function words(string $s): array
    {
        return $s === '' ? [] : explode(' ', $s);
    }

    /** @return list<string> */
    private static function chars(string $s): array
    {
        return $s === '' ? [] : (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Levenshtein distance with two rows. Equal prefixes and suffixes are cut first. PHP's levenshtein() is capped at 255
     * bytes and works on bytes, so it cannot be used.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function distance(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        while ($n > $start && $m > $start && $a[$n - 1] === $b[$m - 1]) {
            $n--;
            $m--;
        }
        $a = array_slice($a, $start, $n - $start);
        $b = array_slice($b, $start, $m - $start);
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            $ai = $a[$i - 1];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $ai === $b[$j - 1] ? 0 : 1;
                $v = $prev[$j - 1] + $cost;
                $x = $prev[$j] + 1;
                if ($x < $v) {
                    $v = $x;
                }
                $y = $cur[$j - 1] + 1;
                if ($y < $v) {
                    $v = $y;
                }
                $cur[$j] = $v;
            }
            $prev = $cur;
        }

        return $prev[$m];
    }
}
