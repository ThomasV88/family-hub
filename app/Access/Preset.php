<?php

namespace App\Access;

enum Preset: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Grandparent = 'grandparent';
    case Guest = 'guest';

    /** @return array<string, AccessLevel> keyed by Module value, every module present, in Module::cases() order */
    public function grants(): array
    {
        $map = match ($this) {
            self::Parent => ['todo' => AccessLevel::Edit, 'wishlist' => AccessLevel::Edit, 'finance' => AccessLevel::Edit, 'health' => AccessLevel::None, 'shopping' => AccessLevel::Edit],
            self::Child, self::Grandparent => ['todo' => AccessLevel::Edit, 'wishlist' => AccessLevel::Edit, 'finance' => AccessLevel::None, 'health' => AccessLevel::None, 'shopping' => AccessLevel::Edit],
            self::Guest => ['todo' => AccessLevel::View, 'wishlist' => AccessLevel::None, 'finance' => AccessLevel::None, 'health' => AccessLevel::None, 'shopping' => AccessLevel::View],
        };

        $grants = [];
        foreach (Module::cases() as $module) {
            $grants[$module->value] = $map[$module->value] ?? AccessLevel::None;
        }

        return $grants;
    }
}
