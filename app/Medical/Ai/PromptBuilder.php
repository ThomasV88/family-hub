<?php

namespace App\Medical\Ai;

use App\Medical\Specialty;

/** The static extraction prompt. No per-run data: the document travels as attached images. */
final class PromptBuilder
{
    private const ISSUE_PATTERN = '/^[a-z_]+(\[\d+\])?(\.[a-z_]+)?:[a-z_]+(:[a-z_]+)?$/D';

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

    /**
     * Prompt for agents without schema enforcement: the static rules, the compact schema inline and, for the single
     * repair call, structural issue codes only (never any text from the document or the first reply).
     *
     * @param  list<string>|null  $issues
     */
    public function buildInline(?array $issues = null): string
    {
        $inline = (string) file_get_contents(resource_path('ai/prompt.inline-json.v1.md'));
        $prompt = $this->build().str_replace('{{schema}}', (string) json_encode(ExtractionSchema::decoded(), JSON_UNESCAPED_SLASHES), $inline);
        if ($issues === null) {
            return $prompt;
        }
        $codes = array_slice(array_values(array_filter(
            $issues,
            fn ($code) => is_string($code) && preg_match(self::ISSUE_PATTERN, $code) === 1,
        )), 0, 20);

        return $prompt."\nYour previous reply was rejected. Problems: ".implode(', ', $codes).". Reply again with ONLY the JSON object.\n";
    }
}
