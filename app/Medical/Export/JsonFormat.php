<?php

namespace App\Medical\Export;

/** The "data file": the normalized model as UTF-8 JSON. It keeps the person's name (it is the family's own file). */
final class JsonFormat
{
    public const CONTENT_TYPE = 'application/json; charset=utf-8';

    /** @param  array<string, mixed>  $model */
    public static function render(array $model): string
    {
        unset($model['subject']['age_years'], $model['scope']);
        if (($model['unverified_notice'] ?? null) === null) {
            unset($model['unverified_notice']);
        }

        return json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
