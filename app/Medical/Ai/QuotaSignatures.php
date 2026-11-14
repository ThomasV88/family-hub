<?php

namespace App\Medical\Ai;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Recognises a usage-limit message from a CLI and extracts an optional reset time.
 * Returns minutes and a time only; no part of the scanned text is returned, stored or logged.
 */
final class QuotaSignatures
{
    /** @return array{minutes: ?int, resetAt: ?CarbonImmutable}|null */
    public static function match(string $agent, string $window, CarbonImmutable $now): ?array
    {
        $haystack = mb_strtolower(str_replace("\u{2019}", "'", $window));
        $signatures = config('ai.quota.signatures.'.$agent, []);

        foreach (is_array($signatures) ? $signatures : [] as $signature) {
            $needle = (string) ($signature['needle'] ?? '');
            if ($needle === '' || ! str_contains($haystack, $needle)) {
                continue;
            }
            $minutes = $signature['minutes'] ?? null;

            return [
                'minutes' => is_int($minutes) ? $minutes : null,
                'resetAt' => self::parseReset($haystack, $now),
            ];
        }

        return null;
    }

    /** Times are read as UTC (the runners pass TZ=UTC to the child). */
    public static function parseReset(string $text, CarbonImmutable $now): ?CarbonImmutable
    {
        $text = mb_strtolower(str_replace("\u{2019}", "'", mb_substr($text, 0, 20000)));
        $candidate = self::fromIso($text) ?? self::fromDuration($text, $now)
            ?? self::fromDate($text) ?? self::fromBareTime($text, $now);

        // The lower bound applies to the time the CLI printed, the upper bound to the buffered result.
        if ($candidate === null || $candidate->lt($now->addMinute())) {
            return null;
        }

        $result = $candidate->addSeconds((int) config('ai.quota.reset_buffer_seconds', 60));
        $max = $now->addDays((int) config('ai.quota.max_reset_days', 8));

        return $result->gt($max) ? null : $result;
    }

    private static function fromIso(string $text): ?CarbonImmutable
    {
        if (preg_match('/\d{4}-\d{2}-\d{2}t\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:z|[+-]\d{2}:?\d{2})?/', $text, $m) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse(strtoupper($m[0]), 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromDuration(string $text, CarbonImmutable $now): ?CarbonImmutable
    {
        if (preg_match('/\bin\s+((?:\d+\s*(?:days?|hours?|hrs?|minutes?|mins?)\b[\s,]*(?:and\s+)?)+)/', $text, $m) !== 1) {
            return null;
        }

        $seconds = 0;
        if (preg_match_all('/(\d+)\s*(days?|hours?|hrs?|minutes?|mins?)\b/', $m[1], $parts, PREG_SET_ORDER) < 1) {
            return null;
        }
        foreach ($parts as $part) {
            $n = min((int) $part[1], 100000);
            $seconds += $n * match ($part[2][0]) {
                'd' => 86400,
                'h' => 3600,
                default => 60,
            };
        }

        return $now->addSeconds($seconds);
    }

    private static function fromDate(string $text): ?CarbonImmutable
    {
        $re = '/\bat\s+([a-z]{3})[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4}),?\s+(\d{1,2}):(\d{2})\s*(am|pm)/';
        if (preg_match($re, $text, $m) !== 1) {
            return null;
        }

        $hour = self::to24((int) $m[4], $m[6]);
        if ($hour === null || (int) $m[5] > 59) {
            return null;
        }

        try {
            return CarbonImmutable::parse(sprintf('%s %d %s %02d:%02d:00', $m[1], $m[2], $m[3], $hour, $m[5]), 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromBareTime(string $text, CarbonImmutable $now): ?CarbonImmutable
    {
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)/', $text, $m) !== 1) {
            return null;
        }

        $hour = self::to24((int) $m[1], $m[3]);
        if ($hour === null || (int) $m[2] > 59) {
            return null;
        }

        $at = $now->utc()->setTime($hour, (int) $m[2], 0);

        return $at->lte($now) ? $at->addDay() : $at;
    }

    private static function to24(int $hour, string $meridiem): ?int
    {
        if ($hour < 1 || $hour > 12) {
            return null;
        }

        return ($hour % 12) + ($meridiem === 'pm' ? 12 : 0);
    }
}
