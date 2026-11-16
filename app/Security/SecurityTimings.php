<?php

namespace App\Security;

/**
 * The two re-confirmation windows. The admin's stored value (Settings > Members > Security) wins when it is a plain
 * integer; a missing, malformed or out-of-range stored value never opens a hole: malformed falls back to the config
 * default (which itself comes from the environment, clamped), out of range is clamped. Reads are memoised per request
 * through AppSettings (bound `scoped`).
 */
class SecurityTimings
{
    public const IDLE = 'security.idle_seconds';

    public const CONFIRM = 'security.confirm_window_seconds';

    public const IDLE_MIN = 60;

    public const IDLE_MAX = 86400;

    public const CONFIRM_MIN = 60;

    public const CONFIRM_MAX = 3600;

    public function __construct(private readonly AppSettings $settings) {}

    /** Sensitive-module idle window, seconds. */
    public function idle(): int
    {
        return $this->read(self::IDLE, (int) config('security.idle_seconds'), self::IDLE_MIN, self::IDLE_MAX);
    }

    /** Window in which a security action accepts the last proof of identity, seconds. */
    public function confirmWindow(): int
    {
        return $this->read(self::CONFIRM, (int) config('security.confirm_window_seconds'), self::CONFIRM_MIN, self::CONFIRM_MAX);
    }

    /** @return array{min: int, max: int} */
    public static function range(string $setting): array
    {
        return $setting === self::IDLE
            ? ['min' => self::IDLE_MIN, 'max' => self::IDLE_MAX]
            : ['min' => self::CONFIRM_MIN, 'max' => self::CONFIRM_MAX];
    }

    public function set(string $setting, int $seconds): void
    {
        ['min' => $min, 'max' => $max] = self::range($setting);
        $this->settings->set($setting, max($min, min($max, $seconds)));
    }

    private function read(string $setting, int $default, int $min, int $max): int
    {
        $stored = $this->settings->get($setting);
        if ($stored === null) {
            return $default;
        }

        return ClampedSeconds::from($stored, $default, $min, $max);
    }
}
