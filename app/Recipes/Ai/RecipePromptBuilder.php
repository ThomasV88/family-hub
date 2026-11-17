<?php

namespace App\Recipes\Ai;

use App\Medical\Ai\PromptBuilder;

/** The static recipe prompt parts. No page text here: RecipeTask puts the untrusted text in its own marked block. */
final class RecipePromptBuilder
{
    /** @param  string  $nonce  per-run marker code, spelled out in the rules so a spoofed marker is recognisable */
    public function rules(string $nonce): string
    {
        $template = (string) file_get_contents(resource_path('ai/recipe.v1.prompt.md'));

        return str_replace(['{{units}}', '{{nonce}}'], [implode(', ', RecipeValidator::UNITS), $nonce], $template);
    }

    /** The compact schema for agents without schema enforcement (Copilot). */
    public function schemaBlock(): string
    {
        $inline = (string) file_get_contents(resource_path('ai/recipe.v1.inline-json.md'));
        $schema = (string) file_get_contents(RecipeTask::schemaFile());
        $compact = json_encode(json_decode($schema, true, 16, JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES);

        return str_replace('{{schema}}', (string) $compact, $inline);
    }

    /**
     * Only for the single repair call: structural issue codes, never anything the page or the first reply held.
     *
     * @param  list<string>|null  $issues
     */
    public function repairSuffix(?array $issues): string
    {
        if ($issues === null) {
            return '';
        }
        $codes = array_slice(array_values(array_filter(
            $issues,
            fn ($code) => is_string($code) && preg_match(PromptBuilder::ISSUE_PATTERN, $code) === 1,
        )), 0, 20);

        return "\nYour previous reply was rejected. Problems: ".implode(', ', $codes).". Reply again with ONLY the JSON object.\n";
    }
}
