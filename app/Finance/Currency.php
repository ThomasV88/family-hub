<?php

namespace App\Finance;

enum Currency: string
{
    case EUR = 'EUR';
    case CZK = 'CZK';
    case PLN = 'PLN';
    case USD = 'USD';
    case GBP = 'GBP';

    /** Decimal places of the minor unit. Explicit table; HUF/JPY are intentionally not allowlisted. */
    public function exponent(): int
    {
        return match ($this) {
            self::EUR, self::CZK, self::PLN, self::USD, self::GBP => 2,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::CZK => 'Kč',
            self::PLN => 'zł',
            self::USD => '$',
            self::GBP => '£',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
