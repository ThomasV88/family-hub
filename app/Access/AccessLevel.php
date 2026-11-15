<?php

namespace App\Access;

enum AccessLevel: int
{
    case None = 0;
    case View = 1;
    case Edit = 2;

    public function label(): string
    {
        return match ($this) {
            self::None => 'none',
            self::View => 'view',
            self::Edit => 'edit',
        };
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }
}
