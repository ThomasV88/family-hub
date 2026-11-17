<?php

namespace App\Recipes;

use App\Shopping\NameKey;

/** Accent-folded search text for a recipe (pure, no database). */
final class RecipeSearchKey
{
    private const DESCRIPTION_CHARS = 200;

    private const MAX_CHARS = 8000;

    /**
     * @param  list<string>  $ingredientNames
     * @param  list<string>  $categoryNames
     */
    public static function build(string $title, ?string $description, array $ingredientNames, array $categoryNames): string
    {
        $parts = [self::title($title)];
        if ($description !== null && trim($description) !== '') {
            $parts[] = NameKey::from(mb_substr($description, 0, self::DESCRIPTION_CHARS));
        }
        foreach ($ingredientNames as $name) {
            $parts[] = self::ingredient($name);
        }
        foreach ($categoryNames as $name) {
            $parts[] = self::category($name);
        }

        $key = implode(' ', array_filter($parts, fn (string $p) => $p !== ''));

        return mb_substr($key, 0, self::MAX_CHARS);
    }

    public static function title(string $title): string
    {
        return NameKey::from($title);
    }

    public static function category(string $name): string
    {
        return NameKey::from($name);
    }

    public static function ingredient(string $name): string
    {
        return NameKey::from($name);
    }
}
