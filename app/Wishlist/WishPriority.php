<?php

namespace App\Wishlist;

enum WishPriority: int
{
    case Top = 2;
    case Love = 1;
    case Nice = 0;

    public function key(): string
    {
        return match ($this) {
            self::Top => 'top',
            self::Love => 'love',
            self::Nice => 'nice',
        };
    }

    public static function fromKey(?string $key): self
    {
        return match ($key) {
            'top' => self::Top,
            'nice' => self::Nice,
            default => self::Love,
        };
    }
}
