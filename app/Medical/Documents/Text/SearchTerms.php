<?php

namespace App\Medical\Documents\Text;

use Illuminate\Support\Str;

/**
 * The query rule of the document search, shared by the search query and the evaluation metrics. Pure PHP: no database
 * code, no logging. Tokens hold letters and digits only, so a later SQL builder can bind them without escaping.
 */
final class SearchTerms
{
    private const MAX_CHARS = 100;

    private const MAX_TOKENS = 8;

    /**
     * One list of prefix alternatives per query token (lower case, not yet folded). A token of 7 or more characters also
     * gets the same token with 1 and 2 trailing characters removed (keeping 5 or more): a prefix query matches only
     * longer stored words, so "paracetamolu" must also try "paracetamol".
     *
     * @return list<list<string>>
     */
    public static function parse(string $q): array
    {
        // Same invisible characters as the stored text loses (TextNormalizer): a pasted query matches the same words.
        $q = trim(TextNormalizer::stripInvisible($q));
        $normalised = \Normalizer::normalize($q, \Normalizer::FORM_C);
        $q = mb_substr($normalised === false ? $q : $normalised, 0, self::MAX_CHARS);

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($q), $m);
        $tokens = array_values(array_filter($m[0], fn (string $t) => mb_strlen($t) >= 2));

        $out = [];
        foreach (array_slice($tokens, 0, self::MAX_TOKENS) as $t) {
            $len = mb_strlen($t);
            $alts = [$t];
            for ($cut = 1; $cut <= 2; $cut++) {
                if ($len >= 7 && $len - $cut >= 5) {
                    $alts[] = mb_substr($t, 0, $len - $cut);
                }
            }
            $out[] = $alts;
        }

        return $out;
    }

    /**
     * How many words of the query the search does not use: words of one character, words past the token limit and
     * words the ASCII search index cannot hold (no Latin letter or digit after the fold). The UI shows a hint for it.
     */
    public static function dropped(string $q): int
    {
        $used = 0;
        foreach (self::parse($q) as $alternatives) {
            foreach ($alternatives as $alt) {
                if (preg_match('/^[a-z0-9]+$/', self::fold($alt)) === 1) {
                    $used++;

                    break;
                }
            }
        }

        return max(0, count(self::words($q)) - $used);
    }

    /**
     * Every word of the (cut, composed, lower case) query: letters and digits only.
     *
     * @return list<string>
     */
    private static function words(string $q): array
    {
        // Same invisible characters as the stored text loses (TextNormalizer): a pasted query matches the same words.
        $q = trim(TextNormalizer::stripInvisible($q));
        $normalised = \Normalizer::normalize($q, \Normalizer::FORM_C);
        $q = mb_substr($normalised === false ? $q : $normalised, 0, self::MAX_CHARS);

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($q), $m);

        return $m[0];
    }

    /** The PHP side of the fold: ASCII transliteration, then lower case. (The SQL side is unaccent, added with the search.) */
    public static function fold(string $s): string
    {
        return mb_strtolower(Str::ascii($s));
    }

    /**
     * @param  list<string>  $alternatives
     */
    public static function matches(array $alternatives, string $word): bool
    {
        $folded = self::fold($word);
        foreach ($alternatives as $alt) {
            $a = self::fold($alt);
            if ($a !== '' && str_starts_with($folded, $a)) {
                return true;
            }
        }

        return false;
    }
}
