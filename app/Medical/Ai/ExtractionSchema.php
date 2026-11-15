<?php

namespace App\Medical\Ai;

/** The versioned extraction contract handed to the CLI (--output-schema) and re-checked by the validator. */
final class ExtractionSchema
{
    public const SCHEMA_VERSION = 1;

    public const PROMPT_VERSION = 1;

    public static function path(): string
    {
        return resource_path('ai/extraction.v1.schema.json');
    }

    /** @return array<string, mixed> */
    public static function decoded(): array
    {
        return json_decode((string) file_get_contents(self::path()), true, 16, JSON_THROW_ON_ERROR);
    }
}
