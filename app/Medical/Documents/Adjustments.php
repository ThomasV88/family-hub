<?php

namespace App\Medical\Documents;

/**
 * The member's brightness, contrast and black-and-white choice for the copy of a page that is sent to the AI (percent,
 * CSS filter semantics). Out-of-range numbers are clamped, anything non-numeric falls back to the default.
 */
final readonly class Adjustments
{
    public const BRIGHTNESS_MIN = 50;

    public const BRIGHTNESS_MAX = 150;

    public const CONTRAST_MIN = 50;

    public const CONTRAST_MAX = 200;

    public function __construct(public int $brightness = 100, public int $contrast = 100, public bool $bw = false) {}

    public static function neutral(): self
    {
        return new self;
    }

    public static function fromArray(?array $a): self
    {
        if ($a === null) {
            return self::neutral();
        }

        return new self(
            self::clamp($a['brightness'] ?? null, self::BRIGHTNESS_MIN, self::BRIGHTNESS_MAX),
            self::clamp($a['contrast'] ?? null, self::CONTRAST_MIN, self::CONTRAST_MAX),
            filter_var($a['bw'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function isNeutral(): bool
    {
        return $this->brightness === 100 && $this->contrast === 100 && ! $this->bw;
    }

    /** @return array{brightness: int, contrast: int, bw: bool} */
    public function toArray(): array
    {
        return ['brightness' => $this->brightness, 'contrast' => $this->contrast, 'bw' => $this->bw];
    }

    private static function clamp(mixed $v, int $min, int $max): int
    {
        if (! is_numeric($v)) {
            return 100;
        }

        return (int) max($min, min($max, round((float) $v)));
    }
}
