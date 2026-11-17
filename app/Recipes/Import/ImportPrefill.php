<?php

namespace App\Recipes\Import;

use App\Models\RecipeImport;
use App\Models\ShoppingUnit;

/**
 * Maps a ready draft (the validated payload only) to the shape of the recipe form. Nothing is saved here: the form is
 * opened prefilled and the member reviews, edits and saves it. Units are looked up by their built-in key, never by a
 * fixed id. The page text, the prompt and the raw model output are not available here and never part of the result.
 */
final class ImportPrefill
{
    /**
     * @return array{title: ?string, description: ?string, servings: ?int, prep_minutes: ?int, cook_minutes: ?int, categories: list<string>, ingredients: list<array<string, mixed>>, steps: list<array{body: string}>, aiFields: list<string>, sourceUrl: string, sourceHost: string, textCut: bool, importId: string}
     */
    public static function fromPayload(RecipeImport $import): array
    {
        $payload = (array) ($import->payload ?? []);
        $units = ShoppingUnit::query()->whereNotNull('key')->pluck('id', 'key');

        $ingredients = [];
        foreach ((array) ($payload['ingredients'] ?? []) as $line) {
            $line = (array) $line;
            $unit = $line['unit'] ?? null;
            $ingredients[] = [
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => self::quantity($line['quantity'] ?? null),
                'unit_id' => is_string($unit) && isset($units[$unit]) ? (int) $units[$unit] : null,
                'note' => isset($line['note']) && $line['note'] !== '' ? (string) $line['note'] : null,
                'original' => isset($line['original']) && $line['original'] !== '' ? (string) $line['original'] : null,
            ];
        }
        $steps = array_values(array_map(fn ($body) => ['body' => (string) $body], array_filter((array) ($payload['steps'] ?? []), 'is_string')));
        $categories = array_values(array_filter((array) ($payload['categories'] ?? []), 'is_string'));

        $result = [
            'title' => self::text($payload['title'] ?? null),
            'description' => self::text($payload['description'] ?? null),
            'servings' => self::int($payload['servings'] ?? null),
            'prep_minutes' => self::int($payload['prep_minutes'] ?? null),
            'cook_minutes' => self::int($payload['cook_minutes'] ?? null),
            'categories' => $categories,
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
        $filled = array_keys(array_filter($result, fn ($v) => $v !== null && $v !== []));

        return $result + [
            'aiFields' => array_values($filled),
            'sourceUrl' => (string) $import->url,
            'sourceHost' => (string) $import->host,
            'textCut' => (bool) $import->text_cut,
            'importId' => (string) $import->id,
        ];
    }

    /**
     * Only the address (after "type it in" for a failed import): the form stays empty but keeps the source link.
     *
     * @return array{sourceUrl: string, sourceHost: string, aiFields: list<string>, textCut: bool, importId: null}
     */
    public static function fromUrl(string $url): array
    {
        return ['sourceUrl' => $url, 'sourceHost' => ImportUrl::host($url), 'aiFields' => [], 'textCut' => false, 'importId' => null];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_numeric($value) && (float) $value === (float) (int) $value) ? (int) $value : null;
    }

    /** 250 and 1.5 become strings the form field takes ("250", "1.5"). */
    private static function quantity(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $text = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}
