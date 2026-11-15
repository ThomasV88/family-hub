<?php

namespace App\Finance;

final class TextNormalizer
{
    private const MAP = [
        'ł' => 'l', 'đ' => 'd', 'ß' => 'ss', 'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe',
    ];

    /** Lowercase, transliterated, diacritic-free, single-spaced [a-z0-9] text for hashing and hints. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, self::MAP);
        $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if ($d !== false) {
            $s = $d;
        }
        $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim($s);
    }
}
