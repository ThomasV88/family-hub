<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Scalar-safe reads for unauthenticated input: `email[]=x` must not crash with "Array to string conversion". */
class RequestInput
{
    public static function string(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    public static function email(Request $request): string
    {
        return mb_strtolower(trim(self::string($request, 'email')));
    }
}
