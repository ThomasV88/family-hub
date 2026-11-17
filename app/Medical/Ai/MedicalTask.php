<?php

namespace App\Medical\Ai;

/** The medical document extraction, exactly as before the task seam existed. */
final class MedicalTask implements AiTask
{
    public function schemaPath(): string
    {
        return ExtractionSchema::path();
    }

    public function prompt(): string
    {
        return (new PromptBuilder)->build();
    }

    public function inlinePrompt(?array $issueCodes): string
    {
        return (new PromptBuilder)->buildInline($issueCodes);
    }

    public function validateFile(string $path): ExtractionOutcome
    {
        return (new ExtractionValidator)->fromFile($path);
    }

    public function inspect(string $json): array
    {
        return (new ExtractionValidator)->inspect($json);
    }
}
