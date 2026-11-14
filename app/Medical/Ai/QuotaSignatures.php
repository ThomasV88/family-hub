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
    private const DATE_RE = '/\bat\s+([a-z]{3})[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4}),?\s+(\d{1,2}):(\d{2})\s*(am|pm)/';

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

    /**
     * Times are read as UTC (the runners pass TZ=UTC to the child).
     *
     * Every parser contributes every time it finds. Stale ones (already past, or less than a minute ahead) are ignored, a
     * time further out than ai.quota.max_reset_days is implausible and ignored, and of the rest the nearest wins: a stale
     * or far-off timestamp earlier in the text can no longer hide a plausible reset, and text that echoes a distant time
     * cannot park an agent for long. The result is buffered and never beyond ai.quota.max_cooldown_hours from now.
     */
    public static function parseReset(string $text, CarbonImmutable $now): ?CarbonImmutable
    {
        $text = mb_strtolower(str_replace("\u{2019}", "'", mb_substr($text, 0, 20000)));
        $candidates = [
            ...self::fromIso($text), ...self::fromDuration($text, $now),
            ...self::fromDate($text),
            // A clock time inside a full date is not a second candidate.
            ...self::fromBareTime((string) preg_replace(self::DATE_RE, ' ', $text), $now),
        ];

        $earliest = $now->addDays(max(1, (int) config('ai.quota.max_reset_days', 8)));
        $best = null;
        foreach ($candidates as $candidate) {
            // The lower bound applies to the time the CLI printed, the upper bound to the buffered result.
            if ($candidate->lt($now->addMinute())) {
                continue;
            }
            $buffered = $candidate->addSeconds((int) config('ai.quota.reset_buffer_seconds', 60));
            if ($buffered->gt($earliest)) {
                continue;
            }
            if ($best === null || $buffered->lt($best)) {
                $best = $buffered;
            }
        }
        if ($best === null) {
            return null;
        }
        $cap = $now->addHours(max(1, (int) config('ai.quota.max_cooldown_hours', 24)));

        return $best->gt($cap) ? $cap : $best;
    }

    /** @return list<CarbonImmutable> */
    private static function fromIso(string $text): array
    {
        if (preg_match_all('/\d{4}-\d{2}-\d{2}t\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:z|[+-]\d{2}:?\d{2})?/', $text, $all) < 1) {
            return [];
        }

        $out = [];
        foreach (array_slice($all[0], 0, 20) as $match) {
            try {
                $out[] = CarbonImmutable::parse(strtoupper($match), 'UTC');
            } catch (Throwable) {
            }
        }

        return $out;
    }

    /** @return list<CarbonImmutable> */
    private static function fromDuration(string $text, CarbonImmutable $now): array
    {
        if (preg_match_all('/\bin\s+((?:\d+\s*(?:days?|hours?|hrs?|minutes?|mins?)\b[\s,]*(?:and\s+)?)+)/', $text, $groups) < 1) {
            return [];
        }

        $out = [];
        foreach (array_slice($groups[1], 0, 20) as $group) {
            if (preg_match_all('/(\d+)\s*(days?|hours?|hrs?|minutes?|mins?)\b/', $group, $parts, PREG_SET_ORDER) < 1) {
                continue;
            }
            $seconds = 0;
            foreach ($parts as $part) {
                $n = min((int) $part[1], 100000);
                $seconds += $n * match ($part[2][0]) {
                    'd' => 86400,
                    'h' => 3600,
                    default => 60,
                };
            }
            $out[] = $now->addSeconds($seconds);
        }

        return $out;
    }

    /** @return list<CarbonImmutable> */
    private static function fromDate(string $text): array
    {
        if (preg_match_all(self::DATE_RE, $text, $all, PREG_SET_ORDER) < 1) {
            return [];
        }

        $out = [];
        foreach (array_slice($all, 0, 20) as $m) {
            $hour = self::to24((int) $m[4], $m[6]);
            if ($hour === null || (int) $m[5] > 59) {
                continue;
            }
            try {
                $out[] = CarbonImmutable::parse(sprintf('%s %d %s %02d:%02d:00', $m[1], $m[2], $m[3], $hour, $m[5]), 'UTC');
            } catch (Throwable) {
            }
        }

        return $out;
    }

    /** @return list<CarbonImmutable> */
    private static function fromBareTime(string $text, CarbonImmutable $now): array
    {
        if (preg_match_all('/\b(\d{1,2}):(\d{2})\s*(am|pm)/', $text, $all, PREG_SET_ORDER) < 1) {
            return [];
        }

        $out = [];
        foreach (array_slice($all, 0, 20) as $m) {
            $hour = self::to24((int) $m[1], $m[3]);
            if ($hour === null || (int) $m[2] > 59) {
                continue;
            }
            $at = $now->utc()->setTime($hour, (int) $m[2], 0);
            $out[] = $at->lte($now) ? $at->addDay() : $at;
        }

        return $out;
    }

    private static function to24(int $hour, string $meridiem): ?int
    {
        if ($hour < 1 || $hour > 12) {
            return null;
        }

        return ($hour % 12) + ($meridiem === 'pm' ? 12 : 0);
    }
}
