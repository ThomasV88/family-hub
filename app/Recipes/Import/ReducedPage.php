<?php

namespace App\Recipes\Import;

/** Sanitised, capped page text for the recipe AI. `cut` is the only trace of what was dropped. */
final class ReducedPage
{
    public const KIND_JSON_LD = 'json_ld';

    public const KIND_MICRODATA = 'microdata';

    public const KIND_TEXT = 'text';

    /** @param  'json_ld'|'microdata'|'text'  $kind */
    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly bool $cut,
    ) {}
}
