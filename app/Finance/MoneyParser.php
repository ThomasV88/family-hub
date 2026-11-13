<?php

namespace App\Finance;

final class MoneyParser
{
    public const BOUND = 10000000000000;

    private const MAX_INT_DIGITS = 13;

    /** Typed amount: optional '-', digits, optional one ',' or '.', up to exponent decimals. Zero rejected. */
    public static function manual(string $raw, Currency $c, bool $allowNegative = false): ?int
    {
        $s = self::squash($raw);
        $neg = false;
        if (str_starts_with($s, '-')) {
            if (! $allowNegative) {
                return null;
            }
            $neg = true;
            $s = substr($s, 1);
        }
        if ($s === '' || substr_count($s, ',') + substr_count($s, '.') > 1) {
            return null;
        }
        $parts = preg_split('/[,.]/', $s);
        $minor = self::build($parts[0], $parts[1] ?? '', $c, $neg, count($parts) === 2);

        return $minor === 0 ? null : $minor;
    }

    /** Bank CSV cell with a profile-defined decimal and grouping separator. Zero allowed. */
    public static function csv(string $raw, Currency $c, string $decimalSep, ?string $groupingSep): ?int
    {
        $s = self::squash($raw);
        $s = self::stripCurrency($s, $c);
        if ($s === null || $s === '') {
            return null;
        }
        $neg = false;
        if (strlen($s) > 2 && $s[0] === '(' && str_ends_with($s, ')')) {
            $neg = true;
            $s = substr($s, 1, -1);
        }
        if (str_ends_with($s, '-')) {
            $neg = ! $neg ? true : $neg;
            $s = substr($s, 0, -1);
        }
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) {
            if ($s[0] === '-') {
                $neg = true;
            }
            $s = substr($s, 1);
        }
        if ($groupingSep !== null && $groupingSep !== '') {
            $s = str_replace($groupingSep, '', $s);
        }
        if ($s === '') {
            return null;
        }
        $parts = explode($decimalSep, $s);
        if (count($parts) > 2) {
            return null;
        }

        return self::build($parts[0], $parts[1] ?? '', $c, $neg, count($parts) === 2);
    }

    private static function squash(string $raw): string
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', "\u{2212}"], ['', '', '', '-'], $raw);

        return trim($s);
    }

    private static function stripCurrency(string $s, Currency $c): ?string
    {
        foreach ([$c->value, $c->symbol()] as $token) {
            if (str_starts_with($s, $token)) {
                return substr($s, strlen($token));
            }
            if (str_ends_with($s, $token)) {
                return substr($s, 0, -strlen($token));
            }
        }

        return $s;
    }

    private static function build(string $int, string $frac, Currency $c, bool $neg, bool $hasSep): ?int
    {
        $exp = $c->exponent();
        if ($int === '' && $frac === '') {
            return null;
        }
        if ($hasSep && $frac === '') {
            return null;
        }
        if (($int !== '' && ! ctype_digit($int)) || ($frac !== '' && ! ctype_digit($frac))) {
            return null;
        }
        if (strlen($frac) > $exp) {
            return null;
        }
        $int = ltrim($int, '0');
        if (strlen($int) > self::MAX_INT_DIGITS) {
            return null;
        }
        $digits = $int.str_pad($frac, $exp, '0');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > self::MAX_INT_DIGITS + $exp) {
            return null;
        }
        $value = (int) $digits;
        if ($value > self::BOUND) {
            return null;
        }

        return $neg ? -$value : $value;
    }
}
