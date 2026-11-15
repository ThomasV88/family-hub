<?php

namespace App\Access;

enum Preset: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Grandparent = 'grandparent';
    case Guest = 'guest';

    /** @return array<string, AccessLevel> keyed by Module value, all four modules present */
    public function grants(): array
    {
        $levels = match ($this) {
            self::Parent => [AccessLevel::Edit, AccessLevel::Edit, AccessLevel::Edit, AccessLevel::None],
            self::Child, self::Grandparent => [AccessLevel::Edit, AccessLevel::Edit, AccessLevel::None, AccessLevel::None],
            self::Guest => [AccessLevel::View, AccessLevel::None, AccessLevel::None, AccessLevel::None],
        };

        $grants = [];
        foreach (Module::cases() as $i => $module) {
            $grants[$module->value] = $levels[$i];
        }

        return $grants;
    }
}
