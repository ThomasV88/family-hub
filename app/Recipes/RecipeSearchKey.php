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

    // Column widths of recipes.title_key, recipe_categories.name_key and recipe_ingredients.name_key.
    // The folded key is ASCII transliteration, which is not length-preserving (one CJK character becomes four to six
    // letters), so every key is cut on code points to its column. Keys are only for search and matching, never shown.
    public const TITLE_KEY_MAX = 255;

    public const CATEGORY_KEY_MAX = 90;

    public const INGREDIENT_KEY_MAX = 360;

    public static function title(string $title): string
    {
        return self::cut(NameKey::from($title), self::TITLE_KEY_MAX);
    }

    public static function category(string $name): string
    {
        return self::cut(NameKey::from($name), self::CATEGORY_KEY_MAX);
    }

    public static function ingredient(string $name): string
    {
        return self::cut(NameKey::from($name), self::INGREDIENT_KEY_MAX);
    }

    private static function cut(string $key, int $max): string
    {
        return mb_strlen($key) <= $max ? $key : rtrim(mb_substr($key, 0, $max));
    }
}
