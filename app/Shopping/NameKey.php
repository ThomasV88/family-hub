<?php

namespace App\Shopping;

use Illuminate\Support\Str;

/** Case- and accent-insensitive uniqueness key for catalog item and store names. */
final class NameKey
{
    public static function from(string $name): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $name));

        $ascii = trim(Str::lower(Str::ascii($collapsed)));
        // A name without a transliterable letter (emoji only) would collapse to "" or "?": keep its own characters instead.
        if ($ascii === '' || preg_match('/^[?\s]+$/', $ascii) === 1) {
            return mb_strtolower($collapsed);
        }

        return $ascii;
    }
}
