<?php

namespace App\Medical\Ai;

/** The versioned extraction contract handed to the CLI (--output-schema) and re-checked by the validator. */
final class ExtractionSchema
{
    public const SCHEMA_VERSION = 1;

    public const PROMPT_VERSION = 1;

    /** Extraction from the stored recognised text (the schema stays version 1). */
    public const TEXT_PROMPT_VERSION = 2;

    public static function path(): string
    {
        return resource_path('ai/extraction.v1.schema.json');
    }

    public static function textPromptPath(): string
    {
        return resource_path('ai/prompt.v2.md');
    }

    /** @return array<string, mixed> */
    public static function decoded(): array
    {
        return json_decode((string) file_get_contents(self::path()), true, 16, JSON_THROW_ON_ERROR);
    }
}
