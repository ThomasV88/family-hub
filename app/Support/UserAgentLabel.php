<?php

namespace App\Support;

/** Tiny user-agent reader for the sessions list. Returns nulls for anything unrecognised. */
class UserAgentLabel
{
    /** @return array{browser: ?string, os: ?string, mobile: bool} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        $browser = match (true) {
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/Firefox\/|FxiOS\//', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS\//', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//', $ua) => 'Safari',
            default => null,
        };

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/', $ua) => 'macOS',
            (bool) preg_match('/Linux|X11/', $ua) => 'Linux',
            default => null,
        };

        $mobile = (bool) preg_match('/iPhone|iPod|Android.*Mobile|Mobile/', $ua);

        return ['browser' => $browser, 'os' => $os, 'mobile' => $mobile];
    }

    /** "Safari on iOS", with unknown parts replaced by the given fallbacks (used for audit meta). */
    public static function device(?string $ua): string
    {
        $p = self::parse($ua);

        return ($p['browser'] ?? 'Unknown browser').' on '.($p['os'] ?? 'unknown system');
    }
}
