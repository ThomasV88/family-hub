<?php

namespace App\Finance;

final class MoneyFormat
{
    /** ASCII decimal such as "-1234.50"; for input prefill and fixtures, never locale display. */
    public static function plain(int $minor, Currency $c): string
    {
        $exp = $c->exponent();
        $sign = $minor < 0 ? '-' : '';
        $abs = (string) abs($minor);
        $abs = str_pad($abs, $exp + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, strlen($abs) - $exp).'.'.substr($abs, -$exp);
    }
}
