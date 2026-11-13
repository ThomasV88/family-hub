<?php

namespace App\Security;

/**
 * Reads a seconds knob from the environment safely (06-REVIEW WR-08). A typo must not silently switch the sensitive
 * gate off (0, a huge milliseconds value) or lock everyone out, so: a value that is not a plain positive integer
 * falls back to the default, and a number outside the range is clamped into it.
 */
class ClampedSeconds
{
    public static function from(mixed $raw, int $default, int $min, int $max): int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }

        if (! is_int($raw) && ! (is_string($raw) && preg_match('/^\d{1,12}$/', $raw) === 1)) {
            return $default;
        }

        $value = (int) $raw;
        if ($value <= 0) {
            return $default;
        }

        return max($min, min($max, $value));
    }
}
