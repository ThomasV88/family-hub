<?php

namespace App\Shopping;

use Illuminate\Support\Str;

/** Case- and accent-insensitive uniqueness key for catalog item and store names. */
final class NameKey
{
    public static function from(string $name): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::lower(Str::ascii($collapsed));
    }
}
