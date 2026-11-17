<?php

namespace App\Medical\Ai\Transcribe;

use App\Medical\Ai\AiTask;
use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\PromptBuilder;

/**
 * Step 1 of the two-step reading: the attached page images (a batch of one document, the first image is page 1) become
 * page text. The prompt is static: nothing of the document or the run enters it.
 */
final class TranscribeTask implements AiTask
{
    private const MAX_ISSUES = 20;

    public function __construct(private readonly int $imageCount) {}

    public function schemaPath(): string
    {
        return resource_path('ai/transcription.v1.schema.json');
    }

    public function prompt(): string
    {
        return (string) file_get_contents(resource_path('ai/transcribe.v1.prompt.md'));
    }

    public function inlinePrompt(?array $issueCodes): string
    {
        $schema = (string) json_encode(json_decode((string) file_get_contents($this->schemaPath()), true, 16), JSON_UNESCAPED_SLASHES);
        $inline = str_replace('{{schema}}', $schema, (string) file_get_contents(resource_path('ai/transcribe.v1.inline-json.md')));
        $codes = array_slice(array_values(array_filter(
            $issueCodes ?? [],
            fn ($code) => is_string($code) && preg_match(PromptBuilder::ISSUE_PATTERN, $code) === 1,
        )), 0, self::MAX_ISSUES);
        $repair = $issueCodes === null ? '' : "\nYour previous reply was rejected. Problems: ".implode(', ', $codes).". Reply again with ONLY the JSON object.\n";

        return $this->prompt().$inline.$repair;
    }

    public function validateFile(string $path): ExtractionOutcome
    {
        return (new TranscriptionValidator)->fromFile($path, $this->imageCount);
    }

    public function inspect(string $json): array
    {
        return (new TranscriptionValidator)->inspect($json, $this->imageCount);
    }
}
