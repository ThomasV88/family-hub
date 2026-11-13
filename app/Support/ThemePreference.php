<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ThemePreference
{
    public const COOKIE = 'fh_theme';

    public const ALLOWED = ['system', 'light', 'dark'];

    public static function fromRequest(Request $request): string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && in_array($value, self::ALLOWED, true) ? $value : 'system';
    }

    public static function themeColor(string $pref): ?string
    {
        return match ($pref) {
            'light' => '#ffffff',
            'dark' => '#222220',
            default => null,
        };
    }
}
