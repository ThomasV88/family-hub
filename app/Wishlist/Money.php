<?php

namespace App\Wishlist;

final class Money
{
    /** "12,50" or "12.5" to minor units; null for empty or invalid input. */
    public static function toMinor(?string $input): ?int
    {
        if ($input === null) {
            return null;
        }
        $input = trim($input);
        if (preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', $input, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function toDecimal(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
