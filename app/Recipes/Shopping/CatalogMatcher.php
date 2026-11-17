<?php

namespace App\Recipes\Shopping;

use App\Shopping\NameKey;
use Illuminate\Support\Collection;

/**
 * Pure matching of an ingredient name against the shopping catalog. Deliberately conservative: an exact name key is
 * a match, one single plausible candidate is only a suggestion the member reviews, anything ambiguous is no match.
 */
final class CatalogMatcher
{
    /** Plural or case endings dropped from the end of the ingredient key (only when 3 or more characters remain). */
    private const ENDINGS = ['es', 's', 'y', 'e', 'i', 'a'];

    /**
     * @param  Collection<int, object{id: int, name: string, name_key: string}>  $catalog
     * @return array{tier: 'exact'|'suggested'|'none', catalogItemId: ?int, catalogName: ?string}
     */
    public function match(string $ingredientName, Collection $catalog): array
    {
        $key = NameKey::from($ingredientName);
        if ($key === '') {
            return $this->none();
        }

        $exact = $catalog->first(fn ($c) => (string) $c->name_key === $key);
        if ($exact !== null) {
            return $this->hit('exact', $exact);
        }

        $stems = [];
        foreach (self::ENDINGS as $ending) {
            if (str_ends_with($key, $ending) && mb_strlen($key) - mb_strlen($ending) >= 3) {
                $stems[] = mb_substr($key, 0, mb_strlen($key) - mb_strlen($ending));
            }
        }
        $words = array_values(array_filter(explode(' ', $key), fn ($w) => $w !== ''));

        $candidates = $catalog->filter(function ($c) use ($stems, $words) {
            $catalogKey = (string) $c->name_key;
            if (in_array($catalogKey, $stems, true)) {
                return true;
            }
            $catalogWords = array_values(array_filter(explode(' ', $catalogKey), fn ($w) => $w !== ''));

            return $catalogWords !== [] && count($words) > count($catalogWords) && array_diff($catalogWords, $words) === [];
        })->values();

        return $candidates->count() === 1 ? $this->hit('suggested', $candidates->first()) : $this->none();
    }

    /** @return array{tier: 'none', catalogItemId: null, catalogName: null} */
    private function none(): array
    {
        return ['tier' => 'none', 'catalogItemId' => null, 'catalogName' => null];
    }

    /** @return array{tier: 'exact'|'suggested', catalogItemId: int, catalogName: string} */
    private function hit(string $tier, object $item): array
    {
        return ['tier' => $tier, 'catalogItemId' => (int) $item->id, 'catalogName' => (string) $item->name];
    }
}
