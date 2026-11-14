<?php

namespace App\Medical\Ai;

use App\Medical\Specialty;

/** The static extraction prompt. No per-run data: the document travels as attached images. */
final class PromptBuilder
{
    public const SEVERITIES = ['mild', 'moderate', 'severe', 'unknown'];

    public function build(): string
    {
        $template = (string) file_get_contents(resource_path('ai/prompt.v1.md'));

        return str_replace(
            ['{{specialties}}', '{{severities}}'],
            [implode(', ', array_map(fn (Specialty $s) => $s->value, Specialty::cases())), implode(', ', self::SEVERITIES)],
            $template,
        );
    }
}
