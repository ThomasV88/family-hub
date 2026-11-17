<?php

namespace App\Medical\Ai;

/**
 * What an agent runs: the output schema, the prompts and the server-side check of the reply. The medical extraction is
 * the default (MedicalTask); other modules supply their own without touching the runners.
 */
interface AiTask
{
    /** Absolute path of the JSON schema file (Codex --output-schema). */
    public function schemaPath(): string;

    /** The full prompt for Codex (sent on stdin). */
    public function prompt(): string;

    /**
     * The prompt for Copilot (schema inline). Issue codes are given only on the single repair call.
     *
     * @param  list<string>|null  $issueCodes
     */
    public function inlinePrompt(?array $issueCodes): string;

    public function validateFile(string $path): ExtractionOutcome;

    /**
     * @return array{outcome: ExtractionOutcome, issues: list<string>}
     */
    public function inspect(string $json): array;
}
